<?php

use think\migration\Migrator;

class Invitations extends Migrator
{
    public function change()
    {
        $this->table('invitations', ['comment' => '团队邀请'])
            ->addColumn('tenant_id', 'integer')
            ->addColumn('inviter_id', 'integer')
            ->addColumn('email', 'string', ['limit' => 191])
            ->addColumn('role', 'string', ['limit' => 16, 'default' => 'member'])
            ->addColumn('token', 'string', ['limit' => 64, 'comment' => 'sha256'])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending', 'comment' => 'pending/accepted/revoked'])
            ->addColumn('expire_time', 'datetime')
            ->addColumn('accept_user_id', 'integer', ['default' => 0])
            ->addColumn('accept_time', 'datetime', ['null' => true])
            ->addTimestamps('create_time', 'update_time')
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['tenant_id', 'status'])
            ->create();
    }
}
