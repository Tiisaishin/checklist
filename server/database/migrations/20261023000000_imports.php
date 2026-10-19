<?php

use think\migration\Migrator;

class Imports extends Migrator
{
    public function change()
    {
        $this->table('import_batches', ['comment' => '批量导入记录'])
            ->addColumn('tenant_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('source', 'string', ['limit' => 191, 'default' => '', 'comment' => '文件名 / 粘贴'])
            ->addColumn('total', 'integer', ['default' => 0])
            ->addColumn('created', 'integer', ['default' => 0])
            ->addColumn('updated', 'integer', ['default' => 0])
            ->addColumn('skipped', 'integer', ['default' => 0])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'done', 'comment' => 'done/reverted'])
            ->addTimestamps('create_time', 'update_time')
            ->addIndex(['tenant_id'])
            ->create();

        $this->table('assets')
            ->addColumn('import_batch_id', 'integer', ['default' => 0])
            ->addColumn('parent_id', 'integer', ['default' => 0, 'comment' => 'SSL 证书所属的域名资产'])
            ->addIndex(['tenant_id', 'type', 'identifier'])
            ->update();
    }
}
