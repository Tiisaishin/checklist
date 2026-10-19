<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Group as GroupModel;
use app\common\detector\DomainDetector;
use app\common\detector\SslDetector;
use app\common\exception\ApiException;
use app\common\model\Asset as AssetModel;
use app\common\model\AssetEvent;
use app\common\service\AssetService;
use app\common\service\AuthContext;
use app\common\service\DetectService;
use app\common\service\QuotaService;
use app\common\service\TimeHelper;
use DateTimeImmutable;

class Asset extends ApiController
{
    public function index()
    {
        $query = AssetModel::where('tenant_id', $this->tenantId());

        $type = $this->request->param('type');
        if ($type) {
            $query->where('type', $type);
        }
        if ($groupId = (int) $this->request->param('group_id')) {
            $query->where('group_id', $groupId);
        }
        $query->where('status', $this->request->param('status', 'active'));
        if ($keyword = trim((string) $this->request->param('keyword'))) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', "%{$keyword}%")->whereOr('identifier', 'like', "%{$keyword}%")->whereOr('provider', 'like', "%{$keyword}%");
            });
        }
        if ($within = (int) $this->request->param('within')) {
            $query->whereNotNull('expire_time')->where('expire_time', '<=', now_utc("+{$within} days"));
        }

        // 无到期时间的排在最后
        $query->orderRaw('CASE WHEN expire_time IS NULL THEN 1 ELSE 0 END, expire_time ASC, id DESC');

        $result          = $this->paginate($query);
        $result['items'] = array_map(fn ($a) => self::present($a), $result['items']);
        return $this->ok($result);
    }

    /** 导出 CSV（带 BOM，Excel 可直接打开） */
    public function export()
    {
        $query = AssetModel::where('tenant_id', $this->tenantId())->where('status', $this->request->param('status', 'active'));
        if ($types = array_filter(explode(',', (string) $this->request->param('type', '')))) {
            $query->whereIn('type', $types);
        }
        if ($groupId = (int) $this->request->param('group_id')) {
            $query->where('group_id', $groupId);
        }
        $groups = GroupModel::where('tenant_id', $this->tenantId())->column('name', 'id');
        $tz     = TimeHelper::tz(AuthContext::user()->timezone);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [lang('field_type'), lang('field_name'), lang('field_identifier'), lang('field_expire_time'), 'days_left', 'provider', lang('field_group_id'), 'price', 'currency', 'auto_detect', 'detect_status', 'notes']);
        $query->order('expire_time')->chunk(500, function ($rows) use ($out, $groups, $tz) {
            foreach ($rows as $a) {
                $p = self::present($a);
                fputcsv($out, [
                    $a->type, $a->name, $a->identifier,
                    $a->expire_time ? TimeHelper::fromDb($a->expire_time)->setTimezone($tz)->format('Y-m-d H:i') : '',
                    $p['days_left'], $a->provider, $groups[$a->group_id] ?? '', $a->price, $a->currency,
                    $a->auto_detect ? 1 : 0, $a->detect_status, (string) $a->notes,
                ]);
            }
        });
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="assets-' . date('Ymd') . '.csv"',
        ]);
    }

    public function read(int $id)
    {
        return $this->ok(self::present($this->find($id)));
    }

    public function save()
    {
        QuotaService::ensure(AuthContext::tenant(), 'assets');
        $data = $this->input();
        $data += ['tenant_id' => $this->tenantId(), 'user_id' => AuthContext::userId(), 'group_id' => GroupModel::resolveId($this->tenantId(), 0)];

        $asset = AssetModel::find(AssetModel::create($data)->id);
        if ($asset->auto_detect && in_array($asset->type, AssetModel::DETECTABLE, true)) {
            DetectService::detect($asset);
            $asset = AssetModel::find($asset->id);
        }
        return $this->ok(self::present($asset), lang('saved'));
    }

    public function update(int $id)
    {
        $asset = $this->find($id);
        $data  = $this->input();
        if (array_key_exists('expire_time', $data) && $data['expire_time'] !== $asset->expire_time) {
            $data['ack_expire_time'] = null;
        }
        $asset->save($data);
        return $this->ok(self::present(AssetModel::find($id)), lang('saved'));
    }

    public function delete(int $id)
    {
        $this->find($id)->delete();
        return $this->ok(null, lang('deleted'));
    }

    public function detect(int $id)
    {
        $asset = $this->find($id);
        if (!in_array($asset->type, AssetModel::DETECTABLE, true)) {
            throw new ApiException(lang('detect_not_supported'));
        }
        DetectService::detect($asset);
        return $this->ok(self::present(AssetModel::find($id)));
    }

    /** 监控变化记录 */
    public function events(int $id)
    {
        $this->find($id);
        return $this->ok(AssetEvent::where('asset_id', $id)->order('id', 'desc')->limit(50)->select());
    }

    public function ack(int $id)
    {
        $asset = $this->find($id);
        AssetService::ack($asset);
        return $this->ok(self::present($asset));
    }

    public function renew(int $id)
    {
        $asset = $this->find($id);
        AssetService::renew($asset, TimeHelper::parseInput($this->request->param('expire_time')));
        return $this->ok(self::present(AssetModel::find($id)), lang('saved'));
    }

    public function snooze(int $id)
    {
        $asset = $this->find($id);
        AssetService::snooze($asset, (string) $this->request->param('period', '1d'));
        return $this->ok(self::present($asset));
    }

    private function find(int $id): AssetModel
    {
        return AssetModel::where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function input(): array
    {
        $data = $this->validated([
            'type'            => 'require|in:' . implode(',', AssetModel::TYPES),
            'name'            => 'require|max:191',
            'identifier'      => 'max:255',
            'url'             => 'max:500',
            'provider'        => 'max:128',
            'group_id'        => 'integer',
            'expire_time'     => 'max:40',
            'auto_detect'     => 'boolean',
            'price'           => 'float|egt:0',
            'currency'        => 'max:8',
            'renew_cycle'     => 'in:monthly,quarterly,yearly,once',
            'auto_renew'      => 'boolean',
            'remind_offsets'  => 'max:128',
            'remind_overdue'  => 'boolean',
            'remind_channels' => 'array',
            'notes'           => 'max:5000',
            'status'          => 'in:active,archived',
        ]);

        if (array_key_exists('expire_time', $data)) {
            $data['expire_time'] = TimeHelper::parseInput($data['expire_time']);
        }
        if (isset($data['remind_offsets'])) {
            $value = is_array($data['remind_offsets']) ? implode(',', $data['remind_offsets']) : (string) $data['remind_offsets'];
            $data['remind_offsets'] = implode(',', parse_offsets($value));
        }
        if (isset($data['remind_channels'])) {
            $data['remind_channels'] = array_values(array_map('intval', $data['remind_channels']));
        }
        $identifier = trim((string) ($data['identifier'] ?? ''));
        if ($identifier !== '') {
            $data['identifier'] = match ($data['type']) {
                'domain' => DomainDetector::normalize($identifier),
                'ssl'    => SslDetector::normalize($identifier),
                default  => $identifier,
            };
        } elseif (in_array($data['type'], AssetModel::DETECTABLE, true) && !empty($data['auto_detect'])) {
            // 域名/证书未填写标识时，尝试从名称推断
            $data['identifier'] = $data['type'] === 'domain' ? DomainDetector::normalize($data['name']) : SslDetector::normalize($data['name']);
        }
        foreach (['auto_detect', 'auto_renew', 'remind_overdue'] as $bool) {
            if (isset($data[$bool])) {
                $data[$bool] = (int) filter_var($data[$bool], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if (array_key_exists('group_id', $data)) {
            $data['group_id'] = GroupModel::resolveId($this->tenantId(), (int) $data['group_id']);
        }
        return $data;
    }

    /** 增加按用户时区计算的剩余天数 */
    public static function present(AssetModel $asset): array
    {
        $data   = $asset->toArray();
        $tz     = TimeHelper::tz(AuthContext::user()->timezone);
        $expire = TimeHelper::fromDb($asset->expire_time);
        $data['days_left'] = $expire
            ? TimeHelper::daysBetween((new DateTimeImmutable('now', TimeHelper::utc()))->setTimezone($tz), $expire->setTimezone($tz))
            : null;
        $data['acked'] = $asset->ack_expire_time && $asset->ack_expire_time === $asset->expire_time;
        $meta          = (array) $asset->detect_meta;
        $data['phase'] = $asset->type === 'domain' ? DomainDetector::phase($data['days_left'], (array) ($meta['status'] ?? [])) : null;
        $data['issue'] = $asset->type === 'ssl' ? (string) ($meta['issue'] ?? '') : null;
        return $data;
    }
}
