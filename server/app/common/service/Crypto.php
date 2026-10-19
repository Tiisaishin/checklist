<?php
namespace app\common\service;

use RuntimeException;

/**
 * AES-256-GCM 对称加密。密钥来自 APP_KEY，未配置时自动生成并保存到 runtime/app.key。
 */
class Crypto
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $raw = (string) config('app.app_key');
        if ($raw === '') {
            $file = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'app.key';
            if (!is_file($file)) {
                @mkdir(dirname($file), 0755, true);
                file_put_contents($file, base64_encode(random_bytes(32)));
                @chmod($file, 0600);
            }
            $raw = trim((string) file_get_contents($file));
        }

        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }
        $decoded = base64_decode($raw, true);
        // 非 base64 或长度不对时，用 sha256 派生
        self::$key = ($decoded !== false && strlen($decoded) === 32) ? $decoded : hash('sha256', $raw, true);

        return self::$key;
    }

    /** 按用途派生子密钥，例如每个空间的保险箱使用独立密钥 */
    private static function keyFor(string $context): string
    {
        return $context === '' ? self::key() : hash_hmac('sha256', $context, self::key(), true);
    }

    public static function encrypt(string $plain, string $context = ''): string
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::keyFor($context), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('encrypt failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload, string $context = ''): ?string
    {
        if (!str_starts_with($payload, 'v1:')) {
            return null;
        }
        $bin = base64_decode(substr($payload, 3), true);
        if ($bin === false || strlen($bin) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::keyFor($context), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function randomString(int $length = 32): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}
