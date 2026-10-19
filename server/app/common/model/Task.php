<?php
namespace app\common\model;

use think\model\concern\SoftDelete;

class Task extends BaseModel
{
    protected $name = 'tasks';
    use SoftDelete;

    public const FREQS = ['daily', 'weekly', 'monthly', 'yearly'];
    public const MODES = ['fixed', 'after_done'];

    protected $deleteTime = 'delete_time';
    protected $hidden = ['delete_time'];

    protected $type = [
        'group_id'        => 'integer',
        'interval'        => 'integer',
        'by_monthday'     => 'integer',
        'by_month'        => 'integer',
        'remind_overdue'  => 'boolean',
        'remind_channels' => 'array',
        'steps'           => 'array',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function offsets(): array
    {
        return parse_offsets((string) $this->remind_offsets);
    }
}
