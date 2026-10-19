<?php
namespace app\common\service;

use InvalidArgumentException;

/**
 * RFC 6238 TOTP。支持纯 Base32 密钥或 otpauth://totp/... 链接
 */
class Totp
{
    /** @return array{secret:string, digits:int, period:int, algorithm:string, issuer:string, account:string} */
    public static function parse(string $input): array
    {
        $input = trim($input);
        $cfg   = ['secret' => '', 'digits' => 6, 'period' => 30, 'algorithm' => 'sha1', 'issuer' => '', 'account' => ''];

        if (str_starts_with(strtolower($input), 'otpauth://')) {
            $parts = parse_url($input);
            parse_str($parts['query'] ?? '', $q);
            $label          = rawurldecode(ltrim($parts['path'] ?? '', '/'));
            $cfg['secret']  = (string) ($q['secret'] ?? '');
            $cfg['digits']  = (int) ($q['digits'] ?? 6);
            $cfg['period']  = (int) ($q['period'] ?? 30);
            $cfg['algorithm'] = strtolower((string) ($q['algorithm'] ?? 'sha1'));
            $cfg['issuer']  = (string) ($q['issuer'] ?? (str_contains($label, ':') ? strstr($label, ':', true) : ''));
            $cfg['account'] = str_contains($label, ':') ? substr(strstr($label, ':'), 1) : $label;
        } else {
            $cfg['secret'] = $input;
        }

        $cfg['secret'] = strtoupper(preg_replace('/[\s=-]/', '', $cfg['secret']));
        if ($cfg['secret'] === '' || !preg_match('/^[A-Z2-7]+$/', $cfg['secret'])) {
            throw new InvalidArgumentException('invalid totp secret');
        }
        if (!in_array($cfg['digits'], [6, 7, 8], true)) {
            $cfg['digits'] = 6;
        }
        if ($cfg['period'] < 10 || $cfg['period'] > 300) {
            $cfg['period'] = 30;
        }
        if (!in_array($cfg['algorithm'], ['sha1', 'sha256', 'sha512'], true)) {
            $cfg['algorithm'] = 'sha1';
        }
        return $cfg;
    }

    /** @return array{code:string, remaining:int, period:int} */
    public static function now(string $input, ?int $time = null): array
    {
        $cfg     = self::parse($input);
        $time    = $time ?? time();
        $counter = intdiv($time, $cfg['period']);
        $hash    = hash_hmac($cfg['algorithm'], pack('J', $counter), self::base32Decode($cfg['secret']), true);
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value   = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        $code    = str_pad((string) ($value % (10 ** $cfg['digits'])), $cfg['digits'], '0', STR_PAD_LEFT);

        return ['code' => $code, 'remaining' => $cfg['period'] - $time % $cfg['period'], 'period' => $cfg['period']];
    }

    private static function base32Decode(string $s): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits     = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
