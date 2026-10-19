<?php

use think\migration\Seeder;

/**
 * 初始化套餐与超级管理员（可重复执行）
 */
class InitSeeder extends Seeder
{
    public function run(): void
    {
        \app\common\service\Installer::seed(
            (string) env('ADMIN_EMAIL', ''),
            (string) env('ADMIN_PASSWORD', '')
        );
    }
}
