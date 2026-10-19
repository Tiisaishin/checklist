<?php

use think\migration\Migrator;

class Vault extends Migrator
{
    public function change()
    {
        $this->table('vault_items', ['comment' => '保险箱条目'])
            ->addColumn('tenant_id', 'integer')
            ->addColumn('group_id', 'integer', ['default' => 0])
            ->addColumn('asset_id', 'integer', ['default' => 0, 'comment' => '关联资产'])
            ->addColumn('user_id', 'integer', ['comment' => '创建人'])
            ->addColumn('type', 'string', ['limit' => 16, 'default' => 'login', 'comment' => 'login/api_key/server/note/other'])
            ->addColumn('title', 'string', ['limit' => 191])
            ->addColumn('url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('visibility', 'string', ['limit' => 16, 'default' => 'team', 'comment' => 'team/private'])
            ->addColumn('secret', 'text', ['null' => true, 'comment' => '加密 JSON：username/password/totp/notes/fields'])
            ->addColumn('has_totp', 'boolean', ['default' => false])
            ->addTimestamps('create_time', 'update_time')
            ->addIndex(['tenant_id', 'group_id'])
            ->create();

        $this->table('vault_logs', ['comment' => '保险箱访问记录'])
            ->addColumn('tenant_id', 'integer')
            ->addColumn('item_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('action', 'string', ['limit' => 16, 'comment' => 'create/update/delete/view/copy/totp'])
            ->addColumn('detail', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('ip', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('create_time', 'datetime')
            ->addIndex(['tenant_id', 'item_id'])
            ->create();

        $this->table('plans')
            ->addColumn('max_vault', 'integer', ['default' => 50, 'after' => 'max_members'])
            ->update();
    }
}
