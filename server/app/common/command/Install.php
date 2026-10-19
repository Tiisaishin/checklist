<?php
namespace app\common\command;

use app\common\service\Installer;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 初始化：执行迁移、写入默认套餐、创建超级管理员（可重复执行）
 */
class Install extends Command
{
    protected function configure()
    {
        $this->setName('checklist:install')->setDescription('Migrate database and seed defaults');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->app->console->call('migrate:run', [], 'console');
        $output->writeln('<info>migrations done</info>');

        $messages = Installer::seed((string) env('ADMIN_EMAIL', ''), (string) env('ADMIN_PASSWORD', ''));
        foreach ($messages as $msg) {
            $output->writeln($msg);
        }
        return 0;
    }
}
