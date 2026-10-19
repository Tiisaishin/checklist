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
 * 域名到期 / SSL 证书监控两个独立视图共用的列表、统计与批量操作（kind = domain | ssl）
 */
class Domain extends ApiController
{
    /**
     * @param string status all / soon（30 天内）/ expired / abnormal（状态异常、证书问题、检测失败）
     */
    public function index()
    {
        $tid   = $this->tenantId();
        $kind  = $this->kind();
        $query = AssetModel::where('tenant_id', $tid)->where('status', $this->request->param('archived') ? 'archived' : 'active')->where('type', $kind);
        if ($groupId = (int) $this->request->param('group_id')) {
            $query->where('group_id', $groupId);
        }
        if ($kw = trim((string) $this->request->param('keyword'))) {
            $query->where(fn ($q) => $q->whereLike('name', "%{$kw}%")->whereOr('identifier', 'like', "%{$kw}%")->whereOr('provider', 'like', "%{$kw}%"));
        }
        switch ($this->request->param('status', 'all')) {
            case 'soon':
                $query->whereNotNull('expire_time')->whereBetween('expire_time', [now_utc(), now_utc($kind === 'ssl' ? '+14 days' : '+30 days')]);
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

        $result          = $this->paginate($query);
        $result['items'] = array_map(fn ($a) => Asset::present($a), $result['items']);
        return $this->ok($result);
    }

    public function stats()
    {
        $tid  = $this->tenantId();
        $kind = $this->kind();
        $base = fn () => AssetModel::where('tenant_id', $tid)->where('status', 'active')->where('type', $kind);
        return $this->ok([
            'total'    => $base()->count(),
            'soon'     => $base()->whereBetween('expire_time', [now_utc(), now_utc($kind === 'ssl' ? '+14 days' : '+30 days')])->count(),
            'expired'  => $base()->where('expire_time', '<', now_utc())->count(),
            'abnormal' => count($this->abnormalIds($tid, $kind)),
            'pending'  => $base()->where('auto_detect', 1)->where('detect_status', '')->count(),
        ]);
    }

    /**
     * 批量操作
     * action: group / offsets / detect / auto_detect / archive / activate / delete
     */
    public function batch()
    {
        $data = $this->validated([
            'ids'        => 'require|array',
            'action'     => 'require|in:group,offsets,detect,auto_detect,archive,activate,delete',
            'value'      => 'max:128',
        ]);
        $tid = $this->tenantId();
        $ids = AssetModel::where('tenant_id', $tid)->whereIn('id', array_map('intval', $data['ids']))->column('id');
        if (!$ids) {
            throw new ApiException(lang('not_found'), 404);
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

    private function kind(): string
    {
        return $this->request->param('kind') === 'ssl' ? 'ssl' : 'domain';
    }

    /** 状态异常 / 证书问题 / 检测失败 */
    private function abnormalIds(int $tid, string $kind): array
    {
        $ids = [];
        $rows = AssetModel::where('tenant_id', $tid)->where('status', 'active')->where('type', $kind)
            ->field('id,type,detect_status,detect_meta')->select();
        foreach ($rows as $a) {
            $meta = (array) $a->detect_meta;
            $bad  = $a->detect_status === 'failed'
                || ($kind === 'ssl' && !empty($meta['issue']))
                || ($kind === 'domain' && DomainDetector::abnormalStatuses((array) ($meta['status'] ?? [])));
            if ($bad) {
                $ids[] = (int) $a->id;
            }
        }
        return $ids;
    }
}
