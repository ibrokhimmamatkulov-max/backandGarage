<?php

namespace App\Services\Owner;

use App\Models\OwnerOtpCode;
use App\Services\Sms\SmsGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Одноразовые коды.
 *
 * Важно: логика одинакова в боевом и демо-режиме. Код всегда генерируется,
 * хешируется, кладётся в базу, имеет срок жизни и счётчик попыток. Демо-режим
 * отличается ровно двумя вещами: код фиксированный и он раскрывается клиенту.
 * Поэтому переключение на реальный шлюз не меняет здесь ни строчки.
 */
class OtpService
{
    public function __construct(
        private readonly SmsGateway $sms,
    ) {
    }

    public function isStubMode(): bool
    {
        return config('otp.mode') === 'stub';
    }

    /**
     * Таблица кодов. Логика одна на владельцев и менеджеров, а коды —
     * раздельные (см. ManagerOtpService): код владельца не должен открывать
     * админку с тем же номером.
     *
     * @return class-string<OwnerOtpCode>|class-string<\App\Models\ManagerOtpCode>
     */
    protected function model(): string
    {
        return OwnerOtpCode::class;
    }

    protected function rateKeyPrefix(): string
    {
        return 'otp:';
    }

    /**
     * @return array{code: OwnerOtpCode, sent: bool, stub_code: ?string}
     */
    public function issue(string $phone, string $purpose = OwnerOtpCode::PURPOSE_AUTH, ?string $ip = null): array
    {
        $phone = PhoneNormalizer::normalize($phone);
        $model = $this->model();

        // Ранее выданные неиспользованные коды гасим: активный код всегда один.
        $model::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $plainCode = $this->generateCode();

        $record = $model::create([
            'phone'      => $phone,
            'code_hash'  => Hash::make($plainCode),
            'purpose'    => $purpose,
            'expires_at' => now()->addSeconds((int) config('otp.ttl_seconds', 300)),
            'attempts'   => 0,
            'ip'         => $ip,
        ]);

        $result = $this->sms->send($phone, strtr(config('sms.templates.otp'), [':code' => $plainCode]));

        // Заглушки (log/stub) ничего не отправляют намеренно — это не ошибка.
        // Ошибка — когда реальный шлюз не смог отправить: код до абонента
        // не дошёл, гасим его, чтобы не висел активным.
        $sent = !$this->sms->delivers() || $result->delivered;

        if (!$sent) {
            $record->update(['consumed_at' => now()]);
        }

        return [
            'code'      => $record,
            'sent'      => $sent,
            'stub_code' => $this->isStubMode() ? $plainCode : null,
        ];
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function verify(string $phone, string $code, string $purpose = OwnerOtpCode::PURPOSE_AUTH): array
    {
        $phone = PhoneNormalizer::normalize($phone);
        $model = $this->model();

        $record = $model::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            return ['ok' => false, 'error' => 'Код не запрашивался или уже использован.'];
        }

        if ($record->isExpired()) {
            return ['ok' => false, 'error' => 'Срок действия кода истёк. Запросите новый.'];
        }

        if ($record->attemptsExhausted()) {
            $record->update(['consumed_at' => now()]);

            return ['ok' => false, 'error' => 'Слишком много попыток. Запросите новый код.'];
        }

        $record->increment('attempts');

        if (!Hash::check($code, $record->code_hash)) {
            $left = $model::MAX_ATTEMPTS - $record->attempts;

            return [
                'ok'    => false,
                'error' => $left > 0
                    ? "Неверный код. Осталось попыток: {$left}."
                    : 'Неверный код. Попытки исчерпаны, запросите новый.',
            ];
        }

        $record->update(['consumed_at' => now()]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{allowed: bool, seconds: int}
     */
    public function checkRateLimit(string $phone, ?string $ip): array
    {
        $phone = PhoneNormalizer::normalize($phone);

        $phoneKey = $this->rateKeyPrefix() . 'phone:' . $phone;
        $ipKey    = $this->rateKeyPrefix() . 'ip:' . ($ip ?? 'unknown');

        if (RateLimiter::tooManyAttempts($phoneKey, (int) config('otp.rate_limit.per_phone'))) {
            return ['allowed' => false, 'seconds' => RateLimiter::availableIn($phoneKey)];
        }

        if ($ip && RateLimiter::tooManyAttempts($ipKey, (int) config('otp.rate_limit.per_ip'))) {
            return ['allowed' => false, 'seconds' => RateLimiter::availableIn($ipKey)];
        }

        RateLimiter::hit($phoneKey, (int) config('otp.rate_limit.per_phone_window'));

        if ($ip) {
            RateLimiter::hit($ipKey, (int) config('otp.rate_limit.per_ip_window'));
        }

        return ['allowed' => true, 'seconds' => 0];
    }

    public function purgeExpired(): int
    {
        $model = $this->model();

        return $model::where('expires_at', '<', Carbon::now()->subDay())->delete();
    }

    private function generateCode(): string
    {
        $length = (int) config('otp.code_length', 4);

        if ($this->isStubMode()) {
            $stub = (string) config('otp.stub_code', '0000');

            Log::critical('[OTP] ДЕМО-РЕЖИМ: выдан фиксированный код.', ['env' => app()->environment()]);

            return str_pad($stub, $length, '0', STR_PAD_LEFT);
        }

        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
