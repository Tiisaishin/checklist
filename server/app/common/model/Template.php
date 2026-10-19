<?php
namespace app\common\model;

class Template extends BaseModel
{
    protected $name = 'templates';
    public const SCENES = [
        'asset_expire', 'task_due',
        'domain_renewed', 'ns_changed', 'domain_status', 'ssl_renewed', 'ssl_error', 'ssl_renew_stuck', 'detect_failed',
    ];
}
