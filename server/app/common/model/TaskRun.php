<?php
namespace app\common\model;

class TaskRun extends BaseModel
{
    protected $name = 'task_runs';
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->field('id,name,email');
    }
}
