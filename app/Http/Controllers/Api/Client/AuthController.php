<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OwnerOtpCode;
use App\Services\Client\ClientAccountService;
use App\Services\Owner\OtpService;
use App\Services\Owner\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вход клиента (арендатора) по номеру и коду из SMS.
 *
 * Аккаунт заводится сам при первом подтверждении номера. Коды лежат в той же
 * таблице, что и коды владельцев, но с отдельным назначением client_auth:
 * код, выданный для кабинета владельца или для заявки, сюда не подойдёт.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly ClientAccountService $clients,
    ) {
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $phone = (string) $request->input('phone', '');

        if (!PhoneNormalizer::isValid($phone)) {
            return $this->error('Validation error', 422, [
                'phone' => ['Введите корректный номер телефона.'],
            ]);
        }

        $limit = $this->otp->checkRateLimit($phone, $request->ip());

        if (!$limit['allowed']) {
            return $this->error(
                "Слишком много запросов. Повторите через {$limit['seconds']} сек.",
                429,
                ['retry_after' => $limit['seconds']]
            );
        }

        $issued = $this->otp->issue($phone, OwnerOtpCode::PURPOSE_CLIENT_AUTH, $request->ip());

        if (!$issued['sent']) {
            return $this->error('Не удалось отправить SMS. Попробуйте позже.', 503);
        }

        return $this->success([
            'expires_in' => (int) config('otp.ttl_seconds'),
            'delivery'   => $this->otp->isStubMode() ? 'stub' : 'sms',
            // Раскрывается ТОЛЬКО в демо-режиме.
            'stub_code'  => $issued['stub_code'],
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $phone = (string) $request->input('phone', '');
        $code  = (string) $request->input('code', '');

        if (!PhoneNormalizer::isValid($phone) || $code === '') {
            return $this->error('Validation error', 422, [
                'code' => ['Введите номер и код из SMS.'],
            ]);
        }

        $result = $this->otp->verify($phone, $code, OwnerOtpCode::PURPOSE_CLIENT_AUTH);

        if (!$result['ok']) {
            return $this->error($result['error'], 422, ['code' => [$result['error']]]);
        }

        $client = $this->clients->findOrCreateByPhone($phone);

        return $this->success([
            'token'  => $this->clients->issueToken($client),
            'client' => $this->clients->present($client),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $client = $request->user('client');

        // Токен владельца или сотрудника под guard 'client' не пройдёт:
        // проверяем модель явно, а не полагаемся только на провайдера.
        if (!$client instanceof Client) {
            return $this->error('Unauthorized', 401);
        }

        return $this->success($this->clients->present($client));
    }

    public function logout(Request $request): JsonResponse
    {
        $client = $request->user('client');

        if ($client instanceof Client) {
            $client->currentAccessToken()?->delete();
        }

        return $this->success(null, 'Вы вышли из аккаунта.');
    }
}
