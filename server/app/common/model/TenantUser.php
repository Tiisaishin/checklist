<?php
namespace app\common\model;

use think\model\Pivot;

class TenantUser extends Pivot
{
    protected $name = 'tenant_users';
    protected $autoWriteTimestamp = 'datetime';

    public const ROLES = ['owner', 'admin', 'member', 'viewer'];
}
