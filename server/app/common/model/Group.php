<?php
namespace app\common\model;

/**
 * 信息分组。每个空间有且只有一个默认分组；分组可单独指定通知渠道（Bot）。
 */
class Group extends BaseModel
{
    protected $name = 'item_groups';

    protected $type = [
        'sort'            => 'integer',
        'is_default'      => 'boolean',
        'remind_channels' => 'array',
    ];

    /** 获取（必要时创建）空间的默认分组 */
    public static function defaultFor(int $tenantId, string $locale = ''): self
    {
        $group = self::where('tenant_id', $tenantId)->where('is_default', 1)->order('id')->find();
        if (!$group) {
            $group = self::create([
                'tenant_id'  => $tenantId,
                'name'       => trans('default_group', [], $locale ?: null),
                'color'      => '#18a058',
                'is_default' => 1,
                'sort'       => 0,
            ]);
        }
        return $group;
    }

    /** 校验分组属于该空间；为空时返回默认分组 ID */
    public static function resolveId(int $tenantId, ?int $groupId): int
    {
        if ($groupId && self::where('tenant_id', $tenantId)->where('id', $groupId)->count()) {
            return $groupId;
        }
        return (int) self::defaultFor($tenantId)->id;
    }
}
