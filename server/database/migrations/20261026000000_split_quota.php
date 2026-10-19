<?php

use think\migration\Migrator;

/**
 * 域名、SSL 证书与其他资产分开限额
 */
class SplitQuota extends Migrator
{
    public function up()
    {
        $this->table('plans')
            ->addColumn('max_domains', 'integer', ['default' => 20, 'after' => 'max_assets', 'comment' => '域名上限，-1 不限'])
            ->addColumn('max_ssl', 'integer', ['default' => 20, 'after' => 'max_domains', 'comment' => 'SSL 证书上限，-1 不限'])
            ->update();
        // 已有套餐沿用原资产上限
        $this->execute('UPDATE plans SET max_domains = max_assets, max_ssl = max_assets');
    }

    public function down()
    {
        $this->table('plans')->removeColumn('max_domains')->removeColumn('max_ssl')->update();
    }
}
