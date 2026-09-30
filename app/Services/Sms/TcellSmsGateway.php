<?php

namespace App\Services\Sms;

use App\Services\Owner\PhoneNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS-шлюз Tcell (SMS_DRIVER=tcell).
 *
 * POST {TCELL_SMS_URL}, JSON {"from", "msisdn", "msg"}, ключ — в заголовке X-API-Key.
 * Номер приходит уже нормализованным к 992XXXXXXXXX — в таком виде его и ждёт шлюз.
 *
 * Текст сообщения в лог не пишется: в нём одноразовый код.
 */
class TcellSmsGateway implements SmsGateway
{
    private const DRIVER = 'tcell';

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $apiKey,
        private readonly string $sender,
        private readonly int $timeout = 10,
    ) {
    }

    public function send(string $phone, string $message): SmsResult
    {
        if (empty($this->url) || empty($this->apiKey)) {
            Log::error('[SMS:tcell] Шлюз не настроен: не заданы TCELL_SMS_URL / TCELL_SMS_API_KEY.');

            return SmsResult::failed(self::DRIVER, 'Шлюз не настроен');
        }

        $masked = PhoneNormalizer::mask($phone);

        try {
            // Повтор — только если соединение не установилось: запрос точно
            // не дошёл. На таймауте ответа или 5xx не повторяем — Tcell мог
            // уже принять сообщение, и абонент получил бы два SMS.
            $response = Http::timeout($this->timeout)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException
                    && str_contains($e->getMessage(), 'cURL error 7'), throw: false)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $this->apiKey])
                ->post($this->url, [
                    'from'   => $this->sender,
                    'msisdn' => $phone,
                    'msg'    => $message,
                ]);
        } catch (\Throwable $e) {
            Log::error('[SMS:tcell] Ошибка соединения со шлюзом.', [
                'phone' => $masked,
                'error' => $e->getMessage(),
            ]);

            return SmsResult::failed(self::DRIVER, 'Шлюз недоступен');
        }

        // TODO: уточнить формат ответа Tcell и проверять признак успеха в теле —
        // пока достаточно HTTP 2xx.
        if (!$response->successful()) {
            Log::error('[SMS:tcell] Шлюз отклонил сообщение.', [
                'phone'  => $masked,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);

            return SmsResult::failed(self::DRIVER, 'Шлюз отклонил сообщение (HTTP ' . $response->status() . ')');
        }

        Log::info('[SMS:tcell] Сообщение отправлено.', [
            'phone'  => $masked,
            'status' => $response->status(),
        ]);

        return SmsResult::sent(self::DRIVER);
    }

    public function delivers(): bool
    {
        return true;
    }
}
