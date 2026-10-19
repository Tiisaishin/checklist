<?php
namespace app\common\model;

class Tenant extends BaseModel
{
    protected $name = 'tenants';
    protected $type = [
        'status'  => 'boolean',
        'plan_id' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
