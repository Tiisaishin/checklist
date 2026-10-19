<?php
namespace app\common\model;

class User extends BaseModel
{
    protected $name = 'users';
    protected $hidden = ['password'];

    protected $type = [
        'is_super'          => 'boolean',
        'status'            => 'boolean',
        'current_tenant_id' => 'integer',
    ];

    public function setPasswordAttr($value): string
    {
        return password_hash((string) $value, PASSWORD_DEFAULT);
    }

    public function checkPassword(string $password): bool
    {
        return password_verify($password, (string) $this->getData('password'));
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, TenantUser::class, 'tenant_id', 'user_id');
    }
}
