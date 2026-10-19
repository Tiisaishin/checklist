<?php
namespace app\common\model;

use app\common\service\Crypto;

/**
 * 通知渠道。config 字段整体加密存储。
 */
class Channel extends BaseModel
{
    protected $name = 'channels';
    public const TYPES = ['telegram'];

    protected $hidden = ['config'];

    public function getConfigAttr($value): array
    {
        if (!$value) {
            return [];
        }
        $plain = Crypto::decrypt((string) $value);
        return $plain ? (json_decode($plain, true) ?: []) : [];
    }

    public function setConfigAttr($value): string
    {
        return Crypto::encrypt(json_encode($value ?: [], JSON_UNESCAPED_UNICODE));
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** 返回给前端的安全信息 */
    public function publicInfo(): array
    {
        $cfg  = $this->config;
        $data = $this->hidden(['config'])->toArray();
        $data['mode']         = $cfg['mode'] ?? 'system';
        $data['bot_username'] = $cfg['bot_username'] ?? '';
        $data['chat_title']   = $cfg['chat_title'] ?? '';
        $data['webhook']      = (bool) ($cfg['webhook'] ?? false);
        $data['has_token']    = !empty($cfg['bot_token']);
        return $data;
    }
}
