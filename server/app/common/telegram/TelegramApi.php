<?php
namespace app\common\telegram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * 极简 Telegram Bot API 客户端
 */
class TelegramApi
{
    private Client $http;

    public function __construct(private string $token, float $timeout = 15)
    {
        $this->http = new Client([
            'base_uri'    => 'https://api.telegram.org/bot' . $token . '/',
            'timeout'     => $timeout,
            'http_errors' => false,
        ]);
    }

    /**
     * @throws RuntimeException
     */
    public function call(string $method, array $params = [], ?float $timeout = null): mixed
    {
        try {
            $options = ['json' => $params];
            if ($timeout !== null) {
                $options['timeout'] = $timeout;
            }
            $res  = $this->http->post($method, $options);
            $body = json_decode((string) $res->getBody(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Telegram: ' . $e->getMessage());
        }

        if (!is_array($body) || empty($body['ok'])) {
            throw new RuntimeException('Telegram: ' . ($body['description'] ?? 'HTTP ' . $res->getStatusCode()));
        }
        return $body['result'];
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function sendMessage(string|int $chatId, string $text, array $extra = []): array
    {
        $params = array_merge([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        try {
            return $this->call('sendMessage', $params);
        } catch (RuntimeException $e) {
            // 用户模板中 HTML 不合法时，降级为纯文本
            if (str_contains($e->getMessage(), "can't parse entities")) {
                unset($params['parse_mode']);
                $params['text'] = strip_tags($text);
                return $this->call('sendMessage', $params);
            }
            throw $e;
        }
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    public function getUpdates(int $offset, int $timeout = 0): array
    {
        return $this->call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ], $timeout + 10);
    }

    public function answerCallback(string $id, string $text = ''): void
    {
        try {
            $this->call('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
        } catch (RuntimeException) {
        }
    }

    public function editReplyMarkup(string|int $chatId, int $messageId, array $markup = []): void
    {
        try {
            $this->call('editMessageReplyMarkup', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'reply_markup' => $markup ?: ['inline_keyboard' => []],
            ]);
        } catch (RuntimeException) {
        }
    }
}
