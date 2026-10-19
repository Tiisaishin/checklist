<?php
namespace app\common\service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * 时区工具：数据库统一 UTC
 */
class TimeHelper
{
    public static function tz(?string $tz): DateTimeZone
    {
        try {
            return new DateTimeZone($tz ?: 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /** 解析数据库中的 UTC 时间 */
    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        if (!$value) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, self::utc());
        } catch (Throwable) {
            return null;
        }
    }

    public static function toDb(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    /** 两个日期（各自所在时区）的自然日差：$target - $today */
    public static function daysBetween(DateTimeImmutable $today, DateTimeImmutable $target): int
    {
        $a = new DateTimeImmutable($today->format('Y-m-d'), self::utc());
        $b = new DateTimeImmutable($target->format('Y-m-d'), self::utc());
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /** 前端传入的时间：带时区偏移的 ISO 字符串或 UTC 字符串 */
    public static function parseInput(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable(trim($value), self::utc());
            return self::toDb($dt);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isValidTimezone(string $tz): bool
    {
        return in_array($tz, DateTimeZone::listIdentifiers(), true);
    }
}
