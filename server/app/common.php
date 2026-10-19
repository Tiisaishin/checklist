<?php
// 应用公共函数

use think\facade\Lang;

if (!function_exists('now_utc')) {
    /** 当前 UTC 时间字符串 */
    function now_utc(string $modify = ''): string
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return ($modify ? $dt->modify($modify) : $dt)->format('Y-m-d H:i:s');
    }
}

if (!function_exists('trans')) {
    /**
     * 指定语言翻译（CLI / 通知场景下按接收者语言渲染）
     */
    function trans(string $key, array $vars = [], ?string $locale = null): string
    {
        static $loaded = [];
        $locale = $locale ?: Lang::getLangSet();
        if (!in_array($locale, config('app.locales'), true)) {
            $locale = config('lang.default_lang');
        }
        if (!isset($loaded[$locale])) {
            Lang::load(config('lang.extend_list')[$locale] ?? [], $locale);
            $loaded[$locale] = true;
        }
        return Lang::get($key, $vars, $locale);
    }
}

if (!function_exists('parse_offsets')) {
    /**
     * 解析提前提醒天数："30,7,3,1,0" => [30,7,3,1,0]
     */
    function parse_offsets(string $value): array
    {
        $list = array_filter(array_map('trim', explode(',', $value)), 'is_numeric');
        $list = array_values(array_unique(array_map('intval', $list)));
        $list = array_filter($list, fn ($v) => $v >= 0 && $v <= 365);
        rsort($list);
        return array_values($list);
    }
}
