<?php
namespace app\common\model;

class Template extends BaseModel
{
    protected $name = 'templates';
    public const SCENES = [
        'domain_expire', 'ssl_expire', 'asset_expire', 'task_due', 'digest',
        'domain_renewed', 'ns_changed', 'domain_status', 'ssl_renewed', 'ssl_error', 'ssl_renew_stuck', 'detect_failed',
    ];
}
