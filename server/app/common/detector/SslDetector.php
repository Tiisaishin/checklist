<?php
namespace app\common\detector;

use RuntimeException;

/**
 * SSL 证书探测：TLS 握手读取对端证书
 */
class SslDetector
{
    /** "https://a.com:8443/x" => ['a.com', 8443] */
    public static function parseTarget(string $input): array
    {
        $value = trim($input);
        if (!preg_match('#^[a-z]+://#i', $value)) {
            $value = 'https://' . $value;
        }
        $parts = parse_url($value);
        $host  = strtolower($parts['host'] ?? '');
        $port  = (int) ($parts['port'] ?? 443);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }
        return [$host, $port];
    }

    public static function normalize(string $input): string
    {
        [$host, $port] = self::parseTarget($input);
        return $port === 443 ? $host : "{$host}:{$port}";
    }

    public function detect(string $target): DetectResult
    {
        [$host, $port] = self::parseTarget($target);
        if ($host === '') {
            throw new RuntimeException('Invalid host');
        }

        $cert = $this->fetch($host, $port, false);
        $info = openssl_x509_parse($cert);
        if (!$info) {
            throw new RuntimeException('Unable to parse certificate');
        }

        // 再做一次校验握手，判断证书是否被信任 / 与域名匹配
        $trusted = true;
        $verifyError = '';
        try {
            $this->fetch($host, $port, true);
        } catch (RuntimeException $e) {
            $trusted = false;
            $verifyError = $e->getMessage();
        }

        $san = [];
        if (!empty($info['extensions']['subjectAltName'])) {
            foreach (explode(',', $info['extensions']['subjectAltName']) as $item) {
                $san[] = trim(str_replace('DNS:', '', $item));
            }
        }

        $issuer    = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '');
        $validTo   = (int) $info['validTo_time_t'];
        $validFrom = (int) $info['validFrom_time_t'];
        $subjectCn = $info['subject']['CN'] ?? '';

        // 问题类型：expired / self_signed / mismatch / untrusted
        $issue = '';
        if ($validTo < time()) {
            $issue = 'expired';
        } elseif (($info['issuer'] ?? []) == ($info['subject'] ?? [])) {
            $issue = 'self_signed';
        } elseif (!self::hostMatches($host, $san ?: [is_array($subjectCn) ? reset($subjectCn) : $subjectCn])) {
            $issue = 'mismatch';
        } elseif (!$trusted) {
            $issue = 'untrusted';
        }

        return new DetectResult(
            gmdate('Y-m-d H:i:s', $validTo),
            is_array($issuer) ? implode(', ', $issuer) : (string) $issuer,
            [
                'source'       => 'tls',
                'host'         => $host,
                'port'         => $port,
                'subject'      => $info['subject']['CN'] ?? '',
                'issuer_cn'    => $info['issuer']['CN'] ?? '',
                'valid_from'   => gmdate('Y-m-d H:i:s', $validFrom),
                'san'          => array_slice($san, 0, 50),
                'serial'       => $info['serialNumberHex'] ?? '',
                'trusted'      => $trusted,
                'verify_error' => mb_substr($verifyError, 0, 200),
                'issue'        => $issue,
                // Let's Encrypt 等 90 天短期证书，应由自动续期提前更换
                'short_lived'  => ($validTo - $validFrom) <= 100 * 86400,
            ]
        );
    }

    /** 证书域名（含 *.example.com 通配）是否覆盖主机名 */
    public static function hostMatches(string $host, array $names): bool
    {
        $host = strtolower($host);
        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === $host) {
                return true;
            }
            if (str_starts_with($name, '*.') && substr_count($host, '.') === substr_count($name, '.')
                && str_ends_with($host, substr($name, 1))) {
                return true;
            }
        }
        return false;
    }

    /** @return \OpenSSLCertificate */
    private function fetch(string $host, int $port, bool $verify)
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$client) {
            $error = error_get_last()['message'] ?? $errstr;
            throw new RuntimeException($errstr ?: $error ?: 'connect failed');
        }
        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            throw new RuntimeException('No certificate returned');
        }
        return $cert;
    }
}
