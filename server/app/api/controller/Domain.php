<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\detector\DomainDetector;
use app\common\exception\ApiException;
use app\common\model\Asset as AssetModel;
use app\common\model\Group as GroupModel;
use app\common\service\AuthContext;
use DateTimeImmutable;

/**
 * 域名与证书视图：域名一行，附带其下的 SSL 证书；支持批量操作
 */
class Domain extends ApiController
{
    /**
     * @param string status all / soon（30 天内）/ expired / abnormal（状态异常、证书问题、检测失败）
     */
    public function index()
    {
        $tid   = $this->tenantId();
        $kind  = $this->request->param('kind', 'domain'); // domain：域名；ssl：未关联域名的独立证书
        $query = AssetModel::where('tenant_id', $tid)->where('status', $this->request->param('archived') ? 'archived' : 'active');
        $query->where('type', $kind === 'ssl' ? 'ssl' : 'domain');
        if ($kind === 'ssl') {
            $query->where('parent_id', 0);
        }
        if ($groupId = (int) $this->request->param('group_id')) {
            $query->where('group_id', $groupId);
        }
        if ($kw = trim((string) $this->request->param('keyword'))) {
            // 同时匹配子证书主机名
            $parents = AssetModel::where('tenant_id', $tid)->where('type', 'ssl')->whereLike('identifier', "%{$kw}%")->column('parent_id');
            $query->where(fn ($q) => $q->whereLike('name', "%{$kw}%")->whereOr('identifier', 'like', "%{$kw}%")->whereOr('provider', 'like', "%{$kw}%")
                ->whereOr('id', 'in', $parents ?: [0]));
        }
        switch ($this->request->param('status', 'all')) {
            case 'soon':
                $query->whereNotNull('expire_time')->whereBetween('expire_time', [now_utc(), now_utc('+30 days')]);
                break;
            case 'expired':
                $query->whereNotNull('expire_time')->where('expire_time', '<', now_utc());
                break;
            case 'abnormal':
                $ids = $this->abnormalIds($tid, $kind);
                $query->whereIn('id', $ids ?: [0]);
                break;
        }
        $query->orderRaw('CASE WHEN expire_time IS NULL THEN 1 ELSE 0 END, expire_time ASC, id DESC');

        $result   = $this->paginate($query);
        $children = [];
        if ($kind !== 'ssl' && $result['items']) {
            $ids = array_map(fn ($a) => $a->id, $result['items']);
            foreach (AssetModel::where('parent_id', 'in', $ids)->where('type', 'ssl')->order('identifier')->select() as $c) {
                $children[$c->parent_id][] = Asset::present($c);
            }
        }
        $result['items'] = array_map(fn ($a) => Asset::present($a) + ['certs' => $children[$a->id] ?? []], $result['items']);
        return $this->ok($result);
    }

    public function stats()
    {
        $tid    = $this->tenantId();
        $base   = fn (string $type) => AssetModel::where('tenant_id', $tid)->where('status', 'active')->where('type', $type);
        return $this->ok([
            'domains'      => $base('domain')->count(),
            'domains_soon' => $base('domain')->whereBetween('expire_time', [now_utc(), now_utc('+30 days')])->count(),
            'domains_expired' => $base('domain')->where('expire_time', '<', now_utc())->count(),
            'certs'        => $base('ssl')->count(),
            'certs_soon'   => $base('ssl')->whereBetween('expire_time', [now_utc(), now_utc('+14 days')])->count(),
            'abnormal'     => count($this->abnormalIds($tid, 'domain')) + count($this->abnormalIds($tid, 'ssl', false)),
            'orphan_certs' => $base('ssl')->where('parent_id', 0)->count(),
        ]);
    }

    /**
     * 批量操作
     * action: group / offsets / detect / auto_detect / archive / activate / delete
     * with_certs: 对域名操作时同时作用于其下的证书
     */
    public function batch()
    {
        $data = $this->validated([
            'ids'        => 'require|array',
            'action'     => 'require|in:group,offsets,detect,auto_detect,archive,activate,delete',
            'value'      => 'max:128',
            'with_certs' => 'boolean',
        ]);
        $tid = $this->tenantId();
        $ids = AssetModel::where('tenant_id', $tid)->whereIn('id', array_map('intval', $data['ids']))->column('id');
        if (!$ids) {
            throw new ApiException(lang('not_found'), 404);
        }
        if (!empty($data['with_certs'])) {
            $ids = array_merge($ids, AssetModel::where('tenant_id', $tid)->whereIn('parent_id', $ids)->column('id'));
        }
        $query = fn () => AssetModel::where('tenant_id', $tid)->whereIn('id', $ids);
        $value = $data['value'] ?? '';
        $now   = now_utc();

        $count = match ($data['action']) {
            'group'       => $query()->update(['group_id' => GroupModel::resolveId($tid, (int) $value), 'update_time' => $now]),
            'offsets'     => $query()->update(['remind_offsets' => implode(',', parse_offsets((string) $value)) ?: '0', 'update_time' => $now]),
            // 交给调度器在 1 分钟内探测
            'detect'      => $query()->where('auto_detect', 1)->whereIn('type', AssetModel::DETECTABLE)->update(['next_detect_time' => $now]),
            'auto_detect' => $query()->whereIn('type', AssetModel::DETECTABLE)->update(['auto_detect' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, 'next_detect_time' => $now, 'update_time' => $now]),
            'archive'     => $query()->update(['status' => 'archived', 'update_time' => $now]),
            'activate'    => $query()->update(['status' => 'active', 'update_time' => $now]),
            'delete'      => $query()->select()->each(fn ($a) => $a->delete())->count(),
        };

        return $this->ok(['affected' => $count], lang('saved'));
    }

    /** 状态异常 / 证书问题 / 检测失败 */
    private function abnormalIds(int $tid, string $kind, bool $onlyOrphan = true): array
    {
        $query = AssetModel::where('tenant_id', $tid)->where('status', 'active')->where('type', $kind === 'ssl' ? 'ssl' : 'domain');
        if ($kind === 'ssl' && $onlyOrphan) {
            $query->where('parent_id', 0);
        }
        $ids = [];
        foreach ($query->field('id,type,detect_status,detect_meta,expire_time')->select() as $a) {
            $meta = (array) $a->detect_meta;
            $bad  = $a->detect_status === 'failed'
                || ($a->type === 'ssl' && !empty($meta['issue']))
                || ($a->type === 'domain' && DomainDetector::abnormalStatuses((array) ($meta['status'] ?? [])));
            if ($bad) {
                $ids[] = (int) $a->id;
            }
        }
        // 域名行：其下证书有问题也算
        if ($kind !== 'ssl') {
            foreach (AssetModel::where('tenant_id', $tid)->where('status', 'active')->where('type', 'ssl')->where('parent_id', '>', 0)->field('parent_id,detect_status,detect_meta')->select() as $c) {
                if ($c->detect_status === 'failed' || !empty(((array) $c->detect_meta)['issue'])) {
                    $ids[] = (int) $c->parent_id;
                }
            }
        }
        return array_values(array_unique($ids));
    }
}
