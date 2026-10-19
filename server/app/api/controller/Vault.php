<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Asset;
use app\common\model\Group as GroupModel;
use app\common\model\User;
use app\common\model\VaultItem;
use app\common\model\VaultLog;
use app\common\service\AuthContext;
use app\common\service\QuotaService;
use app\common\service\Totp;
use app\common\service\VaultService;

class Vault extends ApiController
{
    public function index()
    {
        $query = VaultService::visible($this->tenantId(), AuthContext::userId());
        if ($groupId = (int) $this->request->param('group_id')) {
            $query->where('group_id', $groupId);
        }
        if ($type = $this->request->param('type')) {
            $query->where('type', $type);
        }
        if ($keyword = trim((string) $this->request->param('keyword'))) {
            $query->where(fn ($q) => $q->whereLike('title', "%{$keyword}%")->whereOr('url', 'like', "%{$keyword}%"));
        }
        $query->order('title');

        $result          = $this->paginate($query);
        $names           = User::whereIn('id', array_unique(array_map(fn ($i) => $i->user_id, $result['items'])) ?: [0])->column('name', 'id');
        $result['items'] = array_map(fn ($i) => $this->present($i, $names), $result['items']);

        return $this->ok($result + [
            'unlocked'   => VaultService::remaining(AuthContext::$token),
            'can_reveal' => VaultService::canReveal(AuthContext::$role),
        ]);
    }

    public function save()
    {
        if (!AuthContext::canWrite()) {
            throw new ApiException(lang('forbidden'), 403);
        }
        QuotaService::ensure(AuthContext::tenant(), 'vault');
        $data   = $this->input();
        $secret = VaultService::normalizeSecret((array) $this->request->param('secret', []));

        $item = VaultItem::create($data + [
            'tenant_id' => $this->tenantId(),
            'user_id'   => AuthContext::userId(),
            'secret'    => VaultService::encryptSecret($this->tenantId(), $secret),
            'has_totp'  => $secret['totp'] !== '' ? 1 : 0,
        ]);
        VaultService::log($item, AuthContext::userId(), 'create', '', $this->request->ip());

        return $this->ok($this->present(VaultItem::find($item->id)), lang('saved'));
    }

    public function update(int $id)
    {
        $item = $this->editable($id);
        $data = $this->input();
        // 未提交 secret 时保留原有敏感字段（例如只改标题、分组）
        if ($this->request->has('secret')) {
            VaultService::ensureUnlocked(AuthContext::$token);
            $secret             = VaultService::normalizeSecret((array) $this->request->param('secret', []));
            $data['secret']     = VaultService::encryptSecret($this->tenantId(), $secret);
            $data['has_totp']   = $secret['totp'] !== '' ? 1 : 0;
        }
        $item->save($data);
        VaultService::log($item, AuthContext::userId(), 'update', '', $this->request->ip());

        return $this->ok($this->present(VaultItem::find($id)), lang('saved'));
    }

    public function delete(int $id)
    {
        $item = $this->editable($id);
        VaultService::log($item, AuthContext::userId(), 'delete', $item->title, $this->request->ip());
        $item->delete();
        return $this->ok(null, lang('deleted'));
    }

    public function unlock()
    {
        $data = $this->validated(['password' => 'require']);
        $ttl  = VaultService::unlock(AuthContext::user(), AuthContext::$token, $data['password']);
        return $this->ok(['unlocked' => $ttl]);
    }

    public function lock()
    {
        VaultService::lock(AuthContext::$token);
        return $this->ok(['unlocked' => 0]);
    }

    /** 查看明文（记录访问） */
    public function reveal(int $id)
    {
        $item = $this->revealable($id);
        VaultService::log($item, AuthContext::userId(), 'view', '', $this->request->ip());
        return $this->ok(['secret' => VaultService::decryptSecret($item), 'unlocked' => VaultService::remaining(AuthContext::$token)]);
    }

    public function totp(int $id)
    {
        $item   = $this->revealable($id);
        $secret = VaultService::decryptSecret($item);
        if (empty($secret['totp'])) {
            throw new ApiException(lang('not_found'), 404);
        }
        return $this->ok(Totp::now($secret['totp']));
    }

    /** 前端复制敏感字段时上报 */
    public function log(int $id)
    {
        $item = $this->find($id);
        VaultService::log($item, AuthContext::userId(), 'copy', (string) $this->request->param('field', ''), $this->request->ip());
        return $this->ok();
    }

    public function logs(int $id)
    {
        $item = $this->find($id);
        if (!AuthContext::isAdmin() && $item->user_id !== AuthContext::userId()) {
            throw new ApiException(lang('forbidden'), 403);
        }
        $rows  = VaultLog::where('item_id', $id)->order('id', 'desc')->limit(100)->select()->toArray();
        $names = User::whereIn('id', array_unique(array_column($rows, 'user_id')) ?: [0])->column('name', 'id');
        foreach ($rows as &$r) {
            $r['user_name'] = $names[$r['user_id']] ?? '-';
        }
        return $this->ok($rows);
    }

    private function find(int $id): VaultItem
    {
        return VaultService::visible($this->tenantId(), AuthContext::userId())->where('id', $id)->findOrFail();
    }

    private function editable(int $id): VaultItem
    {
        $item = $this->find($id);
        if (!VaultService::canEdit($item, AuthContext::$role, AuthContext::userId())) {
            throw new ApiException(lang('forbidden'), 403);
        }
        return $item;
    }

    private function revealable(int $id): VaultItem
    {
        $item = $this->find($id);
        if (!VaultService::canReveal(AuthContext::$role)) {
            throw new ApiException(lang('forbidden'), 403);
        }
        VaultService::ensureUnlocked(AuthContext::$token);
        return $item;
    }

    private function input(): array
    {
        $data = $this->validated([
            'title'      => 'require|max:191',
            'type'       => 'require|in:' . implode(',', VaultItem::TYPES),
            'url'        => 'max:500',
            'group_id'   => 'integer',
            'asset_id'   => 'integer',
            'visibility' => 'in:team,private',
        ]);
        $data['group_id'] = GroupModel::resolveId($this->tenantId(), (int) ($data['group_id'] ?? 0));
        if (!empty($data['asset_id']) && !Asset::where('tenant_id', $this->tenantId())->where('id', $data['asset_id'])->count()) {
            $data['asset_id'] = 0;
        }
        return $data;
    }

    /** 列表只返回非敏感信息 + 用户名 */
    private function present(VaultItem $item, array $names = []): array
    {
        $secret = VaultService::decryptSecret($item);
        return $item->toArray() + [
            'username'     => $secret['username'] ?? '',
            'has_password' => ($secret['password'] ?? '') !== '',
            'can_edit'     => VaultService::canEdit($item, AuthContext::$role, AuthContext::userId()),
            'creator'      => $names[$item->user_id] ?? '',
        ];
    }
}
