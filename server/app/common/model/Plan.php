<?php
namespace app\common\model;

class Plan extends BaseModel
{
    protected $name = 'plans';
    protected $type = [
        'max_assets'      => 'integer',
        'max_tasks'       => 'integer',
        'max_channels'    => 'integer',
        'max_members'     => 'integer',
        'max_vault'       => 'integer',
        'detect_interval' => 'integer',
        'price_month'     => 'float',
        'price_year'      => 'float',
        'is_default'      => 'boolean',
        'status'          => 'boolean',
    ];

    public static function defaultPlan(): ?self
    {
        return self::where('is_default', 1)->where('status', 1)->find()
            ?: self::where('status', 1)->order('sort')->find();
    }
}
