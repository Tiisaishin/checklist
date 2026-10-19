<?php

use think\migration\Migrator;

class Monitoring extends Migrator
{
    public function change()
    {
        $this->table('assets')
            ->addColumn('next_detect_time', 'datetime', ['null' => true, 'comment' => '下次探测时间'])
            ->addColumn('fail_count', 'integer', ['default' => 0, 'comment' => '连续探测失败次数'])
            ->addIndex(['auto_detect', 'next_detect_time'])
            ->update();

        $this->table('asset_events', ['comment' => '监控变化事件'])
            ->addColumn('tenant_id', 'integer')
            ->addColumn('asset_id', 'integer')
            ->addColumn('type', 'string', ['limit' => 32, 'comment' => 'domain_renewed/ns_changed/domain_status/ssl_renewed/ssl_error/ssl_renew_stuck/detect_failed'])
            ->addColumn('data', 'text', ['null' => true])
            ->addColumn('create_time', 'datetime')
            ->addIndex(['tenant_id', 'asset_id'])
            ->create();
    }
}
