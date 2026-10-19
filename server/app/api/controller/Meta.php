<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Asset;
use app\common\model\Setting;
use app\common\telegram\BotRegistry;
use DateTimeZone;

class Meta extends ApiController
{
    public function index()
    {
        return $this->ok([
            'site_name'          => (string) Setting::getValue('site_name', 'Checklist'),
            'register_enabled'   => Setting::getValue('register_enabled', '1') === '1',
            'system_bot'         => BotRegistry::systemAvailable() ? BotRegistry::systemUsername() : null,
            'locales'            => config('app.locales'),
            'default_locale'     => config('lang.default_lang'),
            'default_timezone'   => config('app.default_user_timezone'),
            'timezones'          => DateTimeZone::listIdentifiers(),
            'asset_types'        => Asset::TYPES,
            'detectable_types'   => Asset::DETECTABLE,
        ]);
    }
}
