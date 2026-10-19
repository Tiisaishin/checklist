<?php
namespace app\common\model;

use think\facade\Cache;

/**
 * 系统级键值设置
 */
class Setting extends BaseModel
{
    protected $name = 'settings';
    protected $pk = 'name';
    protected $autoWriteTimestamp = false;

    public static function getValue(string $name, $default = null)
    {
        $all = Cache::remember('settings:all', fn () => self::column('value', 'name'), 300);
        return $all[$name] ?? $default;
    }

    public static function setValues(array $values): void
    {
        foreach ($values as $name => $value) {
            $value = is_scalar($value) || $value === null ? $value : json_encode($value);
            if (self::where('name', $name)->count()) {
                self::where('name', $name)->update(['value' => $value]);
            } else {
                self::insert(['name' => $name, 'value' => $value]);
            }
        }
        Cache::delete('settings:all');
    }
}
