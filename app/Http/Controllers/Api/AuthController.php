<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagerOtpCode;
use App\Models\Role;
use App\Models\User;
use App\Services\Manager\ManagerOtpService;
use App\Services\OAuth2Service;
use App\Services\Owner\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'login'    => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                $this->logAuth('warning', 'Login rejected: validation failed', $request, [
                    'errors' => $validator->errors()->toArray(),
                ]);
                return $this->error('Unauthorized', 401);
            }

            $user = User::with('roles')->where('login', $request->login)->first();

            if (!$user) {
                $this->logAuth('warning', 'Login rejected: user not found', $request);
                return $this->error('Unauthorized', 401);
            }

            if (!$user->status) {
                $this->logAuth('warning', 'Login rejected: user is inactive', $request, [
                    'user_id' => $user->id,
                ]);
                return $this->error('Unauthorized', 401);
            }

            if (!Hash::check($request->password, $user->password)) {
                $this->logAuth('warning', 'Login rejected: invalid password', $request, [
                    'user_id' => $user->id,
                ]);
                return $this->error('Unauthorized', 401);
            }

            $token = (new OAuth2Service)->token($request->all());

            if (!isset($token['data'])) {
                $this->logAuth('error', 'Login rejected: token issuance failed', $request, [
                    'user_id'      => $user->id,
                    'oauth_result' => $token,
                ]);
                return $this->error('Unauthorized', 401);
            }

            $token = $token['data'];
            $token['created_at'] = Carbon::now()->toDateTimeString();

            $this->logAuth('info', 'Login successful', $request, ['user_id' => $user->id]);

            return $this->success($this->getAuthData($user, $token), 'Login successful');
        } catch (\Exception $e) {
            $this->logAuth('error', 'Login failed: unhandled exception', $request, [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return $this->error('Unauthorized', 401);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Вход менеджера по SMS-коду
    |--------------------------------------------------------------------------
    | Работает только для учёток, у которых привязан телефон (привязка —
    | после первого входа по паролю, см. requestPhoneBind/verifyPhoneBind).
    | Вход по логину и паролю остаётся как был.
    */

    public function requestOtp(Request $request, ManagerOtpService $otp): JsonResponse
    {
        $phone = (string) $request->input('phone', '');

        if (!PhoneNormalizer::isValid($phone)) {
            return $this->error('Validation error', 422, [
                'phone' => ['Введите корректный номер телефона.'],
            ]);
        }

        $limit = $otp->checkRateLimit($phone, $request->ip());

        if (!$limit['allowed']) {
            return $this->error(
                "Слишком много запросов. Повторите через {$limit['seconds']} сек.",
                429,
                ['retry_after' => $limit['seconds']]
            );
        }

        $stubCode = null;
        $manager = $this->findManagerByPhone($phone);

        // Код уходит только на номер действующего сотрудника, но ответ
        // одинаковый в обоих случаях — по нему нельзя перебором узнать,
        // чьи номера привязаны к админке.
        if ($manager && $manager->status) {
            $issued = $otp->issue($phone, ManagerOtpCode::PURPOSE_AUTH, $request->ip());

            if (!$issued['sent']) {
                return $this->error('Не удалось отправить SMS. Попробуйте позже.', 503);
            }

            $stubCode = $issued['stub_code'];
        }

        return $this->success([
            'expires_in' => (int) config('otp.ttl_seconds'),
            'delivery'   => $otp->isStubMode() ? 'stub' : 'sms',
            // Раскрывается ТОЛЬКО в демо-режиме.
            'stub_code'  => $stubCode,
        ], 'Если номер привязан к учётной записи сотрудника, код отправлен.');
    }

    public function verifyOtp(Request $request, ManagerOtpService $otp): JsonResponse
    {
        $phone = (string) $request->input('phone', '');
        $code  = (string) $request->input('code', '');

        if (!PhoneNormalizer::isValid($phone) || $code === '') {
            return $this->error('Validation error', 422, [
                'code' => ['Введите номер и код из SMS.'],
            ]);
        }

        $result = $otp->verify($phone, $code, ManagerOtpCode::PURPOSE_AUTH);

        if (!$result['ok']) {
            return $this->error($result['error'], 422, ['code' => [$result['error']]]);
        }

        $manager = $this->findManagerByPhone($phone);

        if (!$manager || !$manager->status) {
            $this->logAuth('warning', 'OTP login rejected: no active manager for phone', $request, [
                'phone' => PhoneNormalizer::mask($phone),
            ]);

            return $this->error('Unauthorized', 401);
        }

        // Пароля при входе по коду нет, password grant здесь не подходит —
        // токен выдаётся personal access клиентом (см. миграцию
        // 2026_10_01_000002). Для auth:api он ничем не отличается.
        $issued = $manager->createToken('admin-otp');
        $expiresAt = $issued->token->expires_at;

        $token = [
            'token_type'    => 'Bearer',
            'access_token'  => $issued->accessToken,
            'expires_in'    => $expiresAt ? (int) now()->diffInSeconds($expiresAt) : 0,
            'refresh_token' => null,
            'created_at'    => Carbon::now()->toDateTimeString(),
        ];

        $this->logAuth('info', 'OTP login successful', $request, ['user_id' => $manager->id]);

        return $this->success($this->getAuthData($manager, $token), 'Login successful');
    }

    /**
     * Привязка телефона к своей учётке — шаг 1: код на новый номер.
     * Только для уже вошедшего менеджера (auth:api).
     */
    public function requestPhoneBind(Request $request, ManagerOtpService $otp): JsonResponse
    {
        $phone = (string) $request->input('phone', '');

        if (!PhoneNormalizer::isValid($phone)) {
            return $this->error('Validation error', 422, [
                'phone' => ['Введите корректный номер телефона.'],
            ]);
        }

        if ($this->phoneTakenByAnother($phone, $request->user()->id)) {
            return $this->error('Validation error', 422, [
                'phone' => ['Этот номер уже привязан к другому сотруднику.'],
            ]);
        }

        $limit = $otp->checkRateLimit($phone, $request->ip());

        if (!$limit['allowed']) {
            return $this->error(
                "Слишком много запросов. Повторите через {$limit['seconds']} сек.",
                429,
                ['retry_after' => $limit['seconds']]
            );
        }

        $issued = $otp->issue($phone, ManagerOtpCode::PURPOSE_PHONE_BIND, $request->ip());

        if (!$issued['sent']) {
            return $this->error('Не удалось отправить SMS. Попробуйте позже.', 503);
        }

        return $this->success([
            'expires_in' => (int) config('otp.ttl_seconds'),
            'delivery'   => $otp->isStubMode() ? 'stub' : 'sms',
            'stub_code'  => $issued['stub_code'],
        ], 'Код отправлен.');
    }

    /** Привязка телефона — шаг 2: подтверждение кода. */
    public function verifyPhoneBind(Request $request, ManagerOtpService $otp): JsonResponse
    {
        $phone = (string) $request->input('phone', '');
        $code  = (string) $request->input('code', '');

        if (!PhoneNormalizer::isValid($phone) || $code === '') {
            return $this->error('Validation error', 422, [
                'code' => ['Введите номер и код из SMS.'],
            ]);
        }

        $result = $otp->verify($phone, $code, ManagerOtpCode::PURPOSE_PHONE_BIND);

        if (!$result['ok']) {
            return $this->error($result['error'], 422, ['code' => [$result['error']]]);
        }

        $manager = $request->user();

        // Повторная проверка: между шагами номер мог занять кто-то другой.
        if ($this->phoneTakenByAnother($phone, $manager->id)) {
            return $this->error('Validation error', 422, [
                'phone' => ['Этот номер уже привязан к другому сотруднику.'],
            ]);
        }

        $manager->phone = PhoneNormalizer::normalize($phone);
        $manager->save();

        return $this->success(['phone' => $manager->phone], 'Телефон привязан.');
    }

    /**
     * Телефон в users вводился вручную и хранится как угодно («+992 90…»,
     * «90…»), поэтому сравниваем нормализованные значения. Сотрудников
     * единицы — перебор в памяти дешевле, чем миграция данных.
     */
    private function findManagerByPhone(string $phone): ?User
    {
        $normalized = PhoneNormalizer::normalize($phone);

        return User::with('roles')
            ->whereNotNull('phone')
            ->get()
            ->first(fn (User $user) => PhoneNormalizer::normalize((string) $user->phone) === $normalized);
    }

    private function phoneTakenByAnother(string $phone, int $userId): bool
    {
        $owner = $this->findManagerByPhone($phone);

        return $owner !== null && $owner->id !== $userId;
    }

    /**
     * Write a structured, readable entry to the dedicated auth log channel.
     * Never includes the raw password, only the attempted login and request metadata.
     */
    private function logAuth(string $level, string $message, Request $request, array $context = []): void
    {
        Log::channel('auth')->log($level, $message, array_merge([
            'login'      => $request->input('login'),
            'ip'         => $request->header('x-forwarded-for') ?? $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $context));
    }

    private function getAuthData(User $user, $token): array
    {
        $role_id = $user->roles()->pluck('id')->first();

        $user_info = [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'patronymic' => $user->patronymic,
        ];

        return [
            'id'                    => $user->id,
            'user_id'               => $user->id,
            'token_type'            => $token['token_type'],
            'access_token'          => $token['access_token'],
            'access_token_expires'  => $token['expires_in'],
            'access_token_expDate'  => Carbon::now()->addSeconds($token['expires_in'])->toDateTimeString(),
            'refresh_token'         => $token['refresh_token'],
            'role_id'               => $role_id,
            'user_info'             => $user_info,
            'created_at'            => $token['created_at'],
            'role_ru'               => $user?->roles()->first()?->display_name,
        ];
    }
}
