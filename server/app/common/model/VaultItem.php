<?php
namespace app\common\model;

class VaultItem extends BaseModel
{
    protected $name = 'vault_items';
    protected $hidden = ['secret'];

    public const TYPES = ['login', 'api_key', 'server', 'note', 'other'];

    protected $type = [
        'group_id' => 'integer',
        'asset_id' => 'integer',
        'user_id'  => 'integer',
        'has_totp' => 'boolean',
    ];
}
