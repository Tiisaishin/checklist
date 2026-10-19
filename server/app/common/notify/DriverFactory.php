<?php
namespace app\common\notify;

use InvalidArgumentException;

class DriverFactory
{
    private static array $drivers = [
        'telegram' => TelegramDriver::class,
    ];

    public static function make(string $type): ChannelDriver
    {
        $class = self::$drivers[$type] ?? null;
        if (!$class) {
            throw new InvalidArgumentException("Unsupported channel type: {$type}");
        }
        return new $class();
    }
}
