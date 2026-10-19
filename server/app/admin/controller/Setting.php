<?php
namespace app\admin\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Setting as SettingModel;
use app\common\service\Crypto;
use app\common\telegram\BotRegistry;
use app\common\telegram\TelegramApi;
use RuntimeException;

class Setting extends ApiController
{
    public function index()
    {
        return $this->ok([
            'site_name'             => (string) SettingModel::getValue('site_name', 'Checklist'),
            'register_enabled'      => SettingModel::getValue('register_enabled', '1') === '1',
            'telegram_bot_username' => BotRegistry::systemUsername(),
            'telegram_bot_set'      => BotRegistry::systemToken() !== '',
            'telegram_webhook'      => SettingModel::getValue('telegram_webhook') === '1',
            'webhook_available'     => BotRegistry::webhookBase() !== null,
            'app_url'               => (string) config('app.app_url'),
        ]);
    }

    public function update()
    {
        $data = $this->validated([
            'site_name'          => 'max:64',
            'register_enabled'   => 'boolean',
            'telegram_bot_token' => 'max:128',
            'telegram_bot_clear' => 'boolean',
        ]);

        $values = [];
        if (isset($data['site_name'])) {
            $values['site_name'] = trim($data['site_name']) ?: 'Checklist';
        }
        if (isset($data['register_enabled'])) {
            $values['register_enabled'] = filter_var($data['register_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if (!empty($data['telegram_bot_clear'])) {
            $values += ['telegram_bot_token' => '', 'telegram_bot_username' => '', 'telegram_webhook' => '0'];
        } elseif (!empty($data['telegram_bot_token'])) {
            $token = trim($data['telegram_bot_token']);
            try {
                $api = new TelegramApi($token, 10);
                $me  = $api->getMe();
            } catch (RuntimeException $e) {
                throw new ApiException(lang('invalid_bot_token') . ' (' . $e->getMessage() . ')');
            }
            $values['telegram_bot_token']    = Crypto::encrypt($token);
            $values['telegram_bot_username'] = $me['username'] ?? '';

            $base = BotRegistry::webhookBase();
            $secret = Crypto::randomString(32);
            try {
                if ($base) {
                    $api->setWebhook("{$base}/bot/telegram/system", $secret);
                    $values['telegram_webhook'] = '1';
                } else {
                    $api->deleteWebhook();
                    $values['telegram_webhook'] = '0';
                }
                $values['telegram_webhook_secret'] = $secret;
            } catch (RuntimeException) {
                $values['telegram_webhook'] = '0';
            }
        }

        SettingModel::setValues($values);
        return $this->index();
    }
}
