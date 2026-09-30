<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Реальный SMS-шлюз — Tcell (SMS_DRIVER=provider).
 *
 * Запрос к конкретному API — в sendRequest(); конфигурация, таймаут,
 * ретраи и логирование — общие.
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
     * Tcell: POST {SMS_API_URL} с JSON {"from", "msisdn", "msg"},
     * ключ — в заголовке X-API-Key (SMS_API_KEY), отправитель — SMS_SENDER_NAME.
     * Номер уже нормализован к 992XXXXXXXXX (PhoneNormalizer) — в таком виде его и ждёт шлюз.
     */
    private function sendRequest(string $phone, string $message): bool
    {
        $response = Http::timeout($this->timeout)
            ->retry($this->retries, 500, throw: false)
            ->acceptJson()
            ->withHeaders(['X-API-Key' => $this->key])
            ->post($this->url, [
                'from'   => $this->sender,
                'msisdn' => $phone,
                'msg'    => $message,
            ]);

        if (!$response->successful()) {
            Log::error('[SMS:provider] Tcell отклонил сообщение.', [
                'phone'  => $phone,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 1000),
            ]);

            return false;
        }

        return true;
    }
}
