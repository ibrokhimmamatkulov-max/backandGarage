<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Resources\Owner\OwnerResource;
use App\Models\Owner;
use App\Services\Owner\OtpService;
use App\Services\Owner\OwnerRegistrationService;
use App\Services\Owner\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly OwnerRegistrationService $registration,
    ) {
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:30',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $phone = $request->input('phone');

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

        $issued = $this->otp->issue($phone, ip: $request->ip());

        if (!$issued['sent']) {
            return $this->error('Не удалось отправить SMS. Попробуйте позже.', 503);
        }

        $isStub = $this->otp->isStubMode();

        return $this->success([
            'is_new'     => !$this->registration->exists($phone),
            'expires_in' => (int) config('otp.ttl_seconds'),
            'delivery'   => $isStub ? 'stub' : 'sms',
            // Раскрывается ТОЛЬКО в демо-режиме. См. ТЗ §7.3.
            'stub_code'  => $issued['stub_code'],
        ], $isStub
            ? 'Демо-режим: SMS не отправляется, код подставлен автоматически.'
            : 'Код отправлен.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone'      => 'required|string|max:30',
            'code'       => 'required|string|max:10',
            'first_name' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $phone = $request->input('phone');
        $result = $this->otp->verify($phone, $request->input('code'));

        if (!$result['ok']) {
            return $this->error($result['error'], 422, ['code' => [$result['error']]]);
        }

        $owner = $this->registration->findByPhone($phone);
        $credentials = null;

        if (!$owner) {
            $registered = $this->registration->register($phone, $request->input('first_name'));
            $owner = $registered['owner'];
            $credentials = $registered['credentials'];
        }

        if ($owner->isBlocked()) {
            return $this->error('Аккаунт заблокирован. Обратитесь в поддержку.', 403);
        }

        $owner->forceFill(['last_login_at' => now()])->save();

        return $this->success([
            'token'                => $this->issueToken($owner),
            'owner'                => new OwnerResource($owner),
            'credentials_delivery' => $credentials ? 'screen' : 'sms',
            // Учётка возвращается ТОЛЬКО в демо-режиме, когда SMS не ушло.
            'credentials'          => $credentials,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'login'    => 'required|string|max:60',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $owner = Owner::where('login', $request->input('login'))->first();

        if (!$owner || !Hash::check($request->input('password'), $owner->password)) {
            return $this->error('Неверный логин или пароль.', 401);
        }

        if ($owner->isBlocked()) {
            return $this->error('Аккаунт заблокирован. Обратитесь в поддержку.', 403);
        }

        $owner->forceFill(['last_login_at' => now()])->save();

        return $this->success([
            'token' => $this->issueToken($owner),
            'owner' => new OwnerResource($owner),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('owner')?->currentAccessToken()?->delete();

        return $this->success(null, 'Вы вышли из аккаунта.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:30',
            'code'  => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $result = $this->otp->verify($request->input('phone'), $request->input('code'));

        if (!$result['ok']) {
            return $this->error($result['error'], 422, ['code' => [$result['error']]]);
        }

        $owner = $this->registration->findByPhone($request->input('phone'));

        if (!$owner) {
            return $this->error('Аккаунт с таким телефоном не найден.', 404);
        }

        $reset = $this->registration->resetPassword($owner);

        return $this->success([
            'login'    => $owner->login,
            'password' => $reset['password'],
            'delivery' => $reset['password'] ? 'screen' : 'sms',
        ], 'Пароль обновлён.');
    }

    private function issueToken(Owner $owner): string
    {
        // Срок жизни задаётся в config/sanctum.php (expiration), а не третьим
        // аргументом createToken(): в Sanctum 3.x его ещё нет.
        // Старые токены подчищаем, чтобы они не копились, но активные с других
        // устройств не трогаем — вход с телефона не должен выкидывать с ноутбука.
        $ttlDays = (int) config('otp.token_ttl_days', 30);
        $owner->tokens()->where('created_at', '<', now()->subDays($ttlDays))->delete();

        return $owner->createToken('owner-cabinet')->plainTextToken;
    }
}
