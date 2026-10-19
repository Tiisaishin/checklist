<?php
namespace app\common\detector;

use GuzzleHttp\Client;
use RuntimeException;
use think\facade\Cache;
use Throwable;

/**
 * 域名到期探测：优先 RDAP，失败时回退 WHOIS（43 端口）
 */
class DomainDetector
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 15, 'http_errors' => false, 'headers' => ['Accept' => 'application/rdap+json, application/json']]);
    }

    public static function normalize(string $input): string
    {
        $value = strtolower(trim($input));
        $value = preg_replace('#^[a-z]+://#', '', $value);
        $value = preg_replace('#[/:?\#].*$#', '', $value);
        $value = rtrim($value, '.');
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $value)) {
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
        }
        return $value;
    }

    public function detect(string $domain): DetectResult
    {
        $domain = self::normalize($domain);
        if (!preg_match('/^([a-z0-9-]+\.)+[a-z0-9-]{2,}$/', $domain)) {
            throw new RuntimeException('Invalid domain: ' . $domain);
        }

        $errors = [];
        try {
            $result = $this->rdap($domain);
            if ($result && $result->expireTime) {
                return $result;
            }
        } catch (Throwable $e) {
            $errors[] = 'RDAP: ' . $e->getMessage();
        }

        try {
            $result = $this->whois($domain);
            if ($result->expireTime) {
                return $result;
            }
            $errors[] = 'WHOIS: expiry date not found';
        } catch (Throwable $e) {
            $errors[] = 'WHOIS: ' . $e->getMessage();
        }

        throw new RuntimeException(implode('; ', $errors));
    }

    private function rdapBase(string $tld): ?string
    {
        $services = Cache::remember('rdap:bootstrap', function () {
            $res  = $this->http->get('https://data.iana.org/rdap/dns.json');
            $json = json_decode((string) $res->getBody(), true);
            $map  = [];
            foreach ($json['services'] ?? [] as [$tlds, $urls]) {
                foreach ($tlds as $t) {
                    $map[$t] = $urls[0] ?? null;
                }
            }
            return $map;
        }, 86400 * 7);

        return $services[$tld] ?? null;
    }

    private function rdap(string $domain): ?DetectResult
    {
        $parts = explode('.', $domain);
        // 只查询注册域（例如 a.b.example.com => example.com；多级后缀交给 RDAP 服务器处理）
        $tld  = end($parts);
        $base = $this->rdapBase($tld);
        if (!$base) {
            return null;
        }

        $candidates = [];
        for ($i = count($parts) - 2; $i >= 0; $i--) {
            $candidates[] = implode('.', array_slice($parts, $i));
            if (count($candidates) >= 3) {
                break;
            }
        }

        foreach ($candidates as $name) {
            $this->throttle((string) parse_url($base, PHP_URL_HOST));
            $res = $this->http->get(rtrim($base, '/') . '/domain/' . $name);
            if ($res->getStatusCode() === 404) {
                continue;
            }
            if ($res->getStatusCode() !== 200) {
                throw new RuntimeException('HTTP ' . $res->getStatusCode());
            }
            $json = json_decode((string) $res->getBody(), true) ?: [];

            $events = [];
            foreach ($json['events'] ?? [] as $event) {
                $events[$event['eventAction'] ?? ''] = $event['eventDate'] ?? null;
            }

            $registrar = '';
            foreach ($json['entities'] ?? [] as $entity) {
                if (in_array('registrar', $entity['roles'] ?? [], true)) {
                    foreach ($entity['vcardArray'][1] ?? [] as $field) {
                        if (($field[0] ?? '') === 'fn') {
                            $registrar = (string) $field[3];
                        }
                    }
                }
            }

            $expire = $events['expiration'] ?? null;
            return new DetectResult(
                $expire ? gmdate('Y-m-d H:i:s', strtotime($expire)) : null,
                $registrar,
                [
                    'source'      => 'rdap',
                    'domain'      => $name,
                    'registered'  => isset($events['registration']) ? gmdate('Y-m-d', strtotime($events['registration'])) : null,
                    'status'      => $json['status'] ?? [],
                    'nameservers' => array_values(array_filter(array_map(fn ($ns) => strtolower($ns['ldhName'] ?? ''), $json['nameservers'] ?? []))),
                ]
            );
        }
        return null;
    }

    private function whois(string $domain): DetectResult
    {
        $parts  = explode('.', $domain);
        $tld    = end($parts);
        $server = Cache::remember('whois:server:' . $tld, function () use ($tld) {
            $resp = $this->whoisQuery('whois.iana.org', $tld);
            return preg_match('/^(?:whois|refer):\s*(\S+)/mi', $resp, $m) ? $m[1] : '';
        }, 86400 * 7);

        if (!$server) {
            throw new RuntimeException('No WHOIS server for .' . $tld);
        }

        // 依次尝试注册域候选
        $text = '';
        for ($i = count($parts) - 2; $i >= max(0, count($parts) - 4); $i--) {
            $name = implode('.', array_slice($parts, $i));
            $text = $this->whoisQuery($server, $name);
            if (!preg_match('/no match|not found|no data found|no entries found|status:\s*free/i', $text)) {
                $domain = $name;
                break;
            }
        }

        // 部分注册局会再引用注册商 WHOIS
        if (preg_match('/Registrar WHOIS Server:\s*(\S+)/i', $text, $m) && !$this->parseExpiry($text)) {
            try {
                $text .= "\n" . $this->whoisQuery($m[1], $domain);
            } catch (Throwable) {
            }
        }

        $registrar = preg_match('/^\s*(?:Registrar|Sponsoring Registrar|registrar):\s*(.+)$/mi', $text, $m) ? trim($m[1]) : '';
        $expire    = $this->parseExpiry($text);
        preg_match_all('/^\s*(?:Domain )?Status:\s*([A-Za-z ]+?)(?:\s+https?:\S+)?\s*$/mi', $text, $sm);
        preg_match_all('/^\s*(?:Name Server|nserver):\s*(\S+)/mi', $text, $nm);

        return new DetectResult($expire, $registrar, [
            'source'      => 'whois',
            'server'      => $server,
            'domain'      => $domain,
            'status'      => array_values(array_unique(array_map('trim', $sm[1] ?? []))),
            'nameservers' => array_values(array_unique(array_map(fn ($n) => strtolower(rtrim($n, '.')), $nm[1] ?? []))),
        ]);
    }

    private function parseExpiry(string $text): ?string
    {
        $patterns = [
            '/Registry Expiry Date:\s*(.+)/i',
            '/Registrar Registration Expiration Date:\s*(.+)/i',
            '/Expiration Time:\s*(.+)/i',
            '/Expir(?:y|ation) Date:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
            '/expires?:\s*(.+)/i',
            '/Expiration date:\s*(.+)/i',
            '/renewal date:\s*(.+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $value = trim(preg_replace('/\s*\(.*\)$/', '', $m[1]));
                $value = str_replace(['T', 'Z', '/'], [' ', '', '-'], $value);
                $ts    = strtotime($value . (preg_match('/[+-]\d{2}:?\d{2}$|UTC|GMT/', $value) ? '' : ' UTC'));
                if ($ts) {
                    return gmdate('Y-m-d H:i:s', $ts);
                }
            }
        }
        return null;
    }

    /** 同一查询服务器至少间隔 1 秒，避免批量探测被限流 */
    private function throttle(string $host): void
    {
        $key  = 'detect:last:' . $host;
        $last = (float) Cache::get($key, 0);
        $wait = $last + 1.0 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
        Cache::set($key, microtime(true), 60);
    }

    /** 异常状态（暂停解析、赎回期、待删除等），统一为小写无空格 */
    public static function abnormalStatuses(array $statuses): array
    {
        $abnormal = ['clienthold', 'serverhold', 'redemptionperiod', 'pendingdelete', 'pendingrestore', 'inactive', 'serverinactive'];
        $out      = [];
        foreach ($statuses as $s) {
            $key = strtolower(preg_replace('/[\s_-]+/', '', (string) $s));
            if (in_array($key, $abnormal, true)) {
                $out[] = $key;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * 域名所处阶段：active 正常 / grace 已过期（续费宽限期）/ redemption 赎回期 / pending_delete 待删除 / hold 暂停解析
     */
    public static function phase(?int $daysLeft, array $statuses): string
    {
        $abn = self::abnormalStatuses($statuses);
        return match (true) {
            in_array('pendingdelete', $abn, true)                                   => 'pending_delete',
            in_array('redemptionperiod', $abn, true), in_array('pendingrestore', $abn, true) => 'redemption',
            $daysLeft !== null && $daysLeft < 0                                     => 'grace',
            in_array('clienthold', $abn, true), in_array('serverhold', $abn, true)  => 'hold',
            default                                                                 => 'active',
        };
    }

    private function whoisQuery(string $server, string $query): string
    {
        $this->throttle($server);
        $fp = @fsockopen($server, 43, $errno, $errstr, 10);
        if (!$fp) {
            throw new RuntimeException("{$server}: {$errstr}");
        }
        stream_set_timeout($fp, 10);
        fwrite($fp, $query . "\r\n");
        $out = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    break;
                }
                if ($chunk === false) {
                    break;
                }
            }
            $out .= $chunk;
        }
        fclose($fp);
        return $out;
    }
}
