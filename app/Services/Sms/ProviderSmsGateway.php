<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Заготовка под реального провайдера.
 *
 * Провайдер для Таджикистана ещё не выбран (ТЗ, открытый вопрос №2).
 * Каркас — конфигурация, таймаут, ретраи, разбор ответа, логирование — готов;
 * дописать нужно только тело sendRequest() под конкретный API.
 */
class ProviderSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $key,
        private readonly string $sender,
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
    ) {
    }

    public function send(string $phone, string $message): SmsResult
    {
        if (!$this->isConfigured()) {
            Log::error('[SMS:provider] Шлюз не настроен: не заданы SMS_API_URL / SMS_API_KEY.');

            return SmsResult::failed('provider', 'Шлюз не настроен');
        }

        try {
            $ok = $this->sendRequest($phone, $message);

            return $ok
                ? SmsResult::sent('provider')
                : SmsResult::failed('provider', 'Провайдер отклонил сообщение');
        } catch (\Throwable $e) {
            Log::error('[SMS:provider] Ошибка отправки.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return SmsResult::failed('provider', $e->getMessage());
        }
    }

    public function delivers(): bool
    {
        return true;
    }

    private function isConfigured(): bool
    {
        return !empty($this->url) && !empty($this->key);
    }

    /**
     * ЗДЕСЬ дописывается интеграция под выбранного провайдера.
     *
     * Типовой вид запроса — раскомментировать и поправить payload с разбором
     * ответа под конкретный API; остальной класс менять не нужно:
     *
     *     $response = Http::timeout($this->timeout)
     *         ->retry($this->retries, 500)
     *         ->withToken($this->key)
     *         ->post($this->url, [
     *             'sender'  => $this->sender,
     *             'phone'   => $phone,
     *             'message' => $message,
     *         ]);
     *
     *     return $response->successful();
     */
    private function sendRequest(string $phone, string $message): bool
    {
        throw new RuntimeException(
            'SMS-провайдер не выбран. Реализуйте ProviderSmsGateway::sendRequest() '
            . 'или оставьте SMS_DRIVER=log / OTP_MODE=stub.'
        );
    }
}
