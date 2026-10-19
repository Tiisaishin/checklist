<?php
namespace app\common\model;

use think\model\concern\SoftDelete;

class Asset extends BaseModel
{
    protected $name = 'assets';
    use SoftDelete;

    public const TYPES = ['domain', 'ssl', 'server', 'subscription', 'license', 'account', 'other'];
    /** 支持自动探测到期时间的类型 */
    public const DETECTABLE = ['domain', 'ssl'];

    protected $deleteTime = 'delete_time';
    protected $hidden = ['delete_time'];

    protected $type = [
        'project_id'      => 'integer',
        'auto_detect'     => 'boolean',
        'auto_renew'      => 'boolean',
        'remind_overdue'  => 'boolean',
        'price'           => 'float',
        'detect_meta'     => 'array',
        'remind_channels' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function offsets(): array
    {
        return parse_offsets((string) $this->remind_offsets);
    }
}
