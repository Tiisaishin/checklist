<?php
namespace app\common\model;

class AssetEvent extends BaseModel
{
    protected $name = 'asset_events';
    protected $autoWriteTimestamp = false;

    protected $type = ['data' => 'array'];

    public const TYPES = ['domain_renewed', 'ns_changed', 'domain_status', 'ssl_renewed', 'ssl_error', 'ssl_renew_stuck', 'detect_failed'];
}
