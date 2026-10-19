<?php
namespace app\common\service;

use app\common\detector\DomainDetector;
use app\common\detector\SslDetector;
use app\common\exception\ApiException;
use app\common\model\Asset;
use app\common\model\Group;
use app\common\model\ImportBatch;
use app\common\model\Tenant;
use app\common\model\User;
use DateTimeImmutable;
use think\facade\Db;
use Throwable;

/**
 * 域名批量导入：创建域名资产，并可按主机名（@ / www / 自定义子域）同时创建 SSL 证书监控
 */
class ImportService
{
    public const MAX_ITEMS = 3000;

    /**
     * @param array $items   [{domain, expire, registrar, notes, price}]，expire 为用户时区的 "Y-m-d H:i"
     * @param array $options {group_id, as_domain, ssl_hosts[], auto_detect, domain_offsets, ssl_offsets, existing, currency, source}
     */
    public static function import(Tenant $tenant, User $user, array $items, array $options): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new ApiException(lang('import_too_many', ['max' => self::MAX_ITEMS]), 422);
        }

        $tz         = TimeHelper::tz($user->timezone);
        $groupId    = Group::resolveId((int) $tenant->id, (int) ($options['group_id'] ?? 0));
        $asDomain   = !empty($options['as_domain']);
        $autoDetect = !array_key_exists('auto_detect', $options) || !empty($options['auto_detect']);
        $update     = ($options['existing'] ?? 'skip') === 'update';
        $currency   = strtoupper(substr((string) ($options['currency'] ?? 'USD'), 0, 8)) ?: 'USD';
        $domainOff  = implode(',', parse_offsets((string) ($options['domain_offsets'] ?? '30,7,3,1,0'))) ?: '30,7,3,1,0';
        $sslOff     = implode(',', parse_offsets((string) ($options['ssl_offsets'] ?? '14,7,3,1'))) ?: '14,7,3,1';
        $sslHosts   = self::sslHostPrefixes((array) ($options['ssl_hosts'] ?? []));

        // 1. 规范化
        $rows    = [];
        $invalid = [];
        foreach ($items as $i => $item) {
            $domain = DomainDetector::normalize((string) ($item['domain'] ?? ''));
            if (!preg_match('/^([a-z0-9-]+\.)+[a-z0-9-]{2,}$/', $domain) || strlen($domain) > 253) {
                $invalid[] = ['index' => $i, 'value' => (string) ($item['domain'] ?? '')];
                continue;
            }
            if (isset($rows[$domain])) {
                continue;
            }
            $rows[$domain] = [
                'expire'    => self::parseLocal((string) ($item['expire'] ?? ''), $tz),
                'registrar' => mb_substr(trim((string) ($item['registrar'] ?? '')), 0, 128),
                'notes'     => mb_substr(trim((string) ($item['notes'] ?? '')), 0, 1000),
                'price'     => is_numeric($item['price'] ?? null) ? max(0, (float) $item['price']) : 0,
            ];
        }

        // 2. 计划创建的 SSL 主机
        $sslPlan = [];
        foreach (array_keys($rows) as $domain) {
            foreach ($sslHosts as $prefix) {
                $host = SslDetector::normalize($prefix === '@' ? $domain : "{$prefix}.{$domain}");
                $sslPlan[$host] = $domain;
            }
        }

        // 3. 已存在的条目
        $existingDomains = $asDomain ? self::existing((int) $tenant->id, 'domain', array_keys($rows)) : [];
        $existingSsl     = self::existing((int) $tenant->id, 'ssl', array_keys($sslPlan));

        $newDomains = $asDomain ? array_diff(array_keys($rows), array_keys($existingDomains)) : [];
        $newSsl     = array_diff(array_keys($sslPlan), array_keys($existingSsl));

        // 4. 配额
        $plan  = QuotaService::plan($tenant);
        $limit = (int) ($plan?->max_assets ?? 0);
        if ($limit >= 0) {
            $left = max(0, $limit - QuotaService::usage($tenant)['assets']);
            if (count($newDomains) + count($newSsl) > $left) {
                throw new ApiException(lang('import_quota', ['need' => count($newDomains) + count($newSsl), 'left' => $left]), 403);
            }
        }

        $now   = now_utc();
        $batch = ImportBatch::create([
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
            'source'    => mb_substr((string) ($options['source'] ?? ''), 0, 191),
            'total'     => count($items),
        ]);

        $base = [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'group_id' => $groupId, 'url' => '',
            'detect_status' => '', 'detect_error' => '', 'currency' => $currency, 'renew_cycle' => 'yearly',
            'auto_renew' => 0, 'remind_overdue' => 1, 'status' => 'active', 'import_batch_id' => $batch->id,
            'create_time' => $now, 'update_time' => $now,
        ];

        $updated = 0;
        Db::transaction(function () use ($rows, $newDomains, $newSsl, $sslPlan, $existingDomains, $update, $base, $autoDetect, $domainOff, $sslOff, $tenant, &$updated) {
            // 域名
            $insert = [];
            foreach ($newDomains as $domain) {
                $r        = $rows[$domain];
                $insert[] = $base + [
                    'type' => 'domain', 'name' => self::displayName($domain), 'identifier' => $domain, 'provider' => $r['registrar'],
                    'expire_time' => $r['expire'], 'auto_detect' => $autoDetect ? 1 : 0, 'price' => $r['price'],
                    'remind_offsets' => $domainOff, 'notes' => $r['notes'], 'parent_id' => 0,
                ];
            }
            foreach (array_chunk($insert, 300) as $chunk) {
                Db::name('assets')->insertAll($chunk);
            }

            if ($update) {
                foreach ($existingDomains as $domain => $id) {
                    $r    = $rows[$domain];
                    $data = array_filter([
                        'provider'    => $r['registrar'] ?: null,
                        'expire_time' => $r['expire'],
                        'price'       => $r['price'] ?: null,
                    ], fn ($v) => $v !== null && $v !== '');
                    if ($data) {
                        Asset::where('id', $id)->update($data + ['update_time' => now_utc()]);
                        $updated++;
                    }
                }
            }

            // SSL：关联到同一空间内的域名资产
            $domainIds = self::existing((int) $tenant->id, 'domain', array_unique(array_values($sslPlan)));
            $insert    = [];
            foreach ($newSsl as $host) {
                $insert[] = $base + [
                    'type' => 'ssl', 'name' => self::displayName($host), 'identifier' => $host, 'provider' => '', 'expire_time' => null,
                    'auto_detect' => 1, 'price' => 0, 'remind_offsets' => $sslOff, 'notes' => '',
                    'parent_id' => $domainIds[$sslPlan[$host]] ?? 0,
                ];
            }
            foreach (array_chunk($insert, 300) as $chunk) {
                Db::name('assets')->insertAll($chunk);
            }
        });

        $skipped = ($update ? 0 : count($existingDomains)) + count($existingSsl);
        $batch->save([
            'created' => count($newDomains) + count($newSsl),
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return [
            'batch_id'       => (int) $batch->id,
            'domains'        => count($newDomains),
            'ssl'            => count($newSsl),
            'updated'        => $updated,
            'skipped'        => $skipped,
            'invalid'        => $invalid,
            'existing'       => array_keys($existingDomains),
        ];
    }

    /** 导入进度：新建的条目里多少已完成探测 */
    public static function progress(ImportBatch $batch): array
    {
        $query = fn () => Asset::where('import_batch_id', $batch->id)->where('auto_detect', 1);
        $total = $query()->count();
        return [
            'total'   => $total,
            'done'    => $query()->where('detect_status', '<>', '')->count(),
            'failed'  => $query()->where('detect_status', 'failed')->count(),
            'status'  => $batch->status,
        ];
    }

    /** 撤销导入：删除本批次新建的条目（更新过的旧条目不回滚） */
    public static function revert(ImportBatch $batch): int
    {
        $count = Asset::where('import_batch_id', $batch->id)->count();
        Asset::where('import_batch_id', $batch->id)->select()->each(fn ($a) => $a->delete());
        $batch->save(['status' => 'reverted']);
        return $count;
    }

    /** @return array<string,int> identifier => id */
    private static function existing(int $tenantId, string $type, array $identifiers): array
    {
        $map = [];
        foreach (array_chunk($identifiers, 500) as $chunk) {
            $map += Asset::where('tenant_id', $tenantId)->where('type', $type)->whereIn('identifier', $chunk)->column('id', 'identifier');
        }
        return $map;
    }

    /** "@", "www", "api, mail" => 前缀列表 */
    private static function sslHostPrefixes(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $h) {
            foreach (preg_split('/[\s,，;]+/', strtolower((string) $h)) as $p) {
                $p = trim($p, ". \t");
                if ($p === '@' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $p)) {
                    $out[$p] = true;
                }
            }
        }
        return array_slice(array_keys($out), 0, 10);
    }

    /** 中文域名显示为中文，标识仍用 punycode */
    private static function displayName(string $host): string
    {
        if (str_contains($host, 'xn--') && function_exists('idn_to_utf8')) {
            return idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }
        return $host;
    }

    /** 用户时区的 "Y-m-d H:i" => UTC */
    private static function parseLocal(string $value, \DateTimeZone $tz): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', trim($value))) {
            return null;
        }
        try {
            return TimeHelper::toDb(new DateTimeImmutable(trim($value), $tz));
        } catch (Throwable) {
            return null;
        }
    }
}
