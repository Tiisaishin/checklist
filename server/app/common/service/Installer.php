<?php
namespace app\common\service;

use app\common\model\Plan;
use app\common\model\Setting;
use app\common\model\User;

class Installer
{
    public static function seed(string $adminEmail, string $adminPassword): array
    {
        $messages = [];

        if (!Plan::count()) {
            $plans = [
                ['code' => 'free', 'name' => 'Free', 'max_assets' => 20, 'max_domains' => 20, 'max_ssl' => 20, 'max_tasks' => 20, 'max_channels' => 2, 'max_members' => 1, 'max_vault' => 50, 'detect_interval' => 1440, 'price_month' => 0, 'price_year' => 0, 'is_default' => 1, 'sort' => 1],
                ['code' => 'pro', 'name' => 'Pro', 'max_assets' => 500, 'max_domains' => 500, 'max_ssl' => 500, 'max_tasks' => 500, 'max_channels' => 10, 'max_members' => 5, 'max_vault' => 1000, 'detect_interval' => 360, 'price_month' => 5, 'price_year' => 50, 'is_default' => 0, 'sort' => 2],
                ['code' => 'team', 'name' => 'Team', 'max_assets' => -1, 'max_domains' => -1, 'max_ssl' => -1, 'max_tasks' => -1, 'max_channels' => -1, 'max_members' => 50, 'max_vault' => -1, 'detect_interval' => 60, 'price_month' => 19, 'price_year' => 190, 'is_default' => 0, 'sort' => 3],
            ];
            foreach ($plans as $plan) {
                Plan::create($plan + ['currency' => 'USD', 'status' => 1]);
            }
            $messages[] = 'default plans created';
        }

        if (Setting::getValue('site_name') === null) {
            Setting::setValues([
                'site_name'        => 'Checklist',
                'register_enabled' => '1',
            ]);
        }

        if ($adminEmail !== '' && $adminPassword !== '' && !User::where('email', strtolower($adminEmail))->count()) {
            $user = AuthService::register(strtolower($adminEmail), $adminPassword, 'Admin', (string) config('lang.default_lang'));
            $user->save(['is_super' => 1]);
            $messages[] = "super admin created: {$adminEmail}";
        }

        return $messages;
    }
}
