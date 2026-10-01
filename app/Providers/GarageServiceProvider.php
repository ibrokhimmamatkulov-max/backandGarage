<?php

namespace App\Providers;

use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\ProviderSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\StubSmsGateway;
use App\Services\Sms\TcellSmsGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Регистрация сервисов Гаража 2.0.
 *
 * Отдельный провайдер, а не AppServiceProvider: существующий файл трогаем
 * минимально, чтобы новая функциональность не перемешивалась с легаси.
 */
class GarageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, function () {
            // Демо-режим OTP всегда тянет за собой заглушку отправки,
            // какой бы драйвер ни стоял в конфиге: код уже раскрыт клиенту,
            // отправлять его ещё и в SMS бессмысленно.
            if (config('otp.mode') === 'stub') {
                return new StubSmsGateway();
            }

            return match (config('sms.driver')) {
                'stub'     => new StubSmsGateway(),
                'tcell'    => new TcellSmsGateway(
                    config('sms.tcell.url'),
                    config('sms.tcell.api_key'),
                    config('sms.tcell.sender'),
                    (int) config('sms.tcell.timeout', 10),
                ),
                'provider' => new ProviderSmsGateway(
                    config('sms.provider.url'),
                    config('sms.provider.key'),
                    config('sms.sender_name'),
                    (int) config('sms.provider.timeout', 10),
                    (int) config('sms.provider.retries', 2),
                ),
                default    => new LogSmsGateway(),
            };
        });
    }

    public function boot(): void
    {
        $this->guardStubMode();
    }

    /**
     * Второй предохранитель демо-режима (ТЗ §7.3).
     *
     * Пока OTP_MODE=stub, войти можно под любым номером телефона. На боевом
     * окружении это захват любого аккаунта, поэтому нужен отдельный явный
     * флаг — два разных флага случайно не совпадут.
     */
    private function guardStubMode(): void
    {
        if (config('otp.mode') !== 'stub') {
            return;
        }

        if (!$this->app->isProduction()) {
            return;
        }

        if (!config('otp.allow_stub_in_production')) {
            throw new RuntimeException(
                'OTP_MODE=stub на боевом окружении. В демо-режиме войти можно под любым '
                . 'номером телефона. Выставьте OTP_MODE=sms или, если это осознанный '
                . 'закрытый стенд, поднимите OTP_ALLOW_STUB_IN_PRODUCTION=true.'
            );
        }

        Log::critical('[OTP] ДЕМО-РЕЖИМ АКТИВЕН НА PRODUCTION. Вход возможен под любым номером телефона.');
    }
}
