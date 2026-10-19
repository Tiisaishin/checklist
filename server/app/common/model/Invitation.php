<?php
namespace app\common\model;

class Invitation extends BaseModel
{
    protected $name = 'invitations';
    protected $hidden = ['token'];

    public const TTL_DAYS = 7;

    public function isValid(): bool
    {
        return $this->status === 'pending' && $this->expire_time > now_utc();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
