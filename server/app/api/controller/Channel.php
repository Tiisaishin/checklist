<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Channel as ChannelModel;
use app\common\service\AuthContext;
use app\common\service\ChannelService;
use app\common\service\Crypto;
use app\common\service\NotificationService;
use app\common\service\QuotaService;
use app\common\telegram\BotRegistry;
use Throwable;

/**
 * 通知渠道：每个用户管理自己的渠道
 */
class Channel extends ApiController
{
    public function index()
    {
        $list = ChannelModel::where('tenant_id', $this->tenantId())
            ->where('user_id', AuthContext::userId())
            ->order('id')
            ->select();

        return $this->ok([
            'items'      => array_map(fn ($c) => $this->present($c), $list->all()),
            'system_bot' => BotRegistry::systemAvailable() ? BotRegistry::systemUsername() : null,
        ]);
    }

    public function read(int $id)
    {
        return $this->ok($this->present($this->find($id)));
    }

    public function save()
    {
        QuotaService::ensure(AuthContext::tenant(), 'channels');
        $data = $this->validated([
            'type'      => 'require|in:' . implode(',', ChannelModel::TYPES),
            'name'      => 'max:64',
            'mode'      => 'require|in:system,custom',
            'bot_token' => 'requireIf:mode,custom|max:128',
            'chat_id'   => 'max:64',
        ]);

        $channel = ChannelService::createTelegram($this->tenantId(), AuthContext::user(), $data);
        return $this->ok($this->present(ChannelModel::find($channel->id)), lang('saved'));
    }

    public function update(int $id)
    {
        $channel = $this->find($id);
        $data    = $this->validated([
            'name'      => 'max:64',
            'enabled'   => 'boolean',
            'chat_id'   => 'max:64',
            'bot_token' => 'max:128',
        ]);

        if (isset($data['name'])) {
            $channel->save(['name' => trim($data['name']) ?: $channel->name]);
        }

        // 更换自建 Bot 的 Token
        if (!empty($data['bot_token']) && ($channel->config['mode'] ?? '') === 'custom') {
            $cfg = ChannelService::verifyBotToken($data['bot_token']);
            $channel->save(['config' => $cfg, 'status' => 'pending', 'target' => '', 'bind_code' => Crypto::randomString(16)]);
            ChannelService::syncWebhook($channel);
        }

        if (!empty($data['chat_id'])) {
            ChannelService::bindChatId($channel, trim($data['chat_id']));
        }

        if (isset($data['enabled']) && $channel->target !== '') {
            $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
            $channel->save(['status' => $enabled ? 'active' : 'disabled']);
        }

        return $this->ok($this->present(ChannelModel::find($id)), lang('saved'));
    }

    public function delete(int $id)
    {
        ChannelService::delete($this->find($id));
        return $this->ok(null, lang('deleted'));
    }

    public function test(int $id)
    {
        $channel = $this->find($id);
        if ($channel->status !== 'active') {
            throw new ApiException(lang('channel_not_active'));
        }
        try {
            NotificationService::sendNow($channel, trans('tg_test_message', [], AuthContext::user()->locale));
        } catch (Throwable $e) {
            $channel->save(['last_error' => mb_substr($e->getMessage(), 0, 480)]);
            throw new ApiException(lang('send_failed') . ': ' . $e->getMessage());
        }
        $channel->save(['last_error' => '']);
        return $this->ok(null, lang('ok'));
    }

    /** 重新生成绑定码（换绑到其他聊天） */
    public function rebind(int $id)
    {
        $channel = $this->find($id);
        $channel->save(['bind_code' => Crypto::randomString(16), 'status' => 'pending']);
        ChannelService::syncWebhook($channel);
        return $this->ok($this->present(ChannelModel::find($id)));
    }

    private function find(int $id): ChannelModel
    {
        return ChannelModel::where('tenant_id', $this->tenantId())->where('user_id', AuthContext::userId())->findOrFail($id);
    }

    private function present(ChannelModel $channel): array
    {
        $data               = $channel->publicInfo();
        $data['bind_links'] = ChannelService::bindLinks($channel);
        unset($data['bind_code']);
        return $data;
    }
}
