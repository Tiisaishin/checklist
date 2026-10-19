<?php
namespace app\common\model;

class Template extends BaseModel
{
    protected $name = 'templates';
    public const SCENES = ['asset_expire', 'task_due'];
}
