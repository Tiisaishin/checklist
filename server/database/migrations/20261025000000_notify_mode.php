<?php

use think\migration\Migrator;

class NotifyMode extends Migrator
{
    public function change()
    {
        $this->table('users')
            ->addColumn('notify_mode', 'string', ['limit' => 16, 'default' => 'smart', 'after' => 'notify_time', 'comment' => 'single/digest/smart'])
            ->update();
    }
}
