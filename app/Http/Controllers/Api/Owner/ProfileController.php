<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Resources\Owner\OwnerResource;
use App\Models\Owner;
use App\Models\OwnerOtpCode;
use App\Services\Owner\OtpService;
use App\Services\Owner\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->success(new OwnerResource($request->user('owner')));
    }

    public function update(Request $request): JsonResponse
    {
        $owner = $request->user('owner');

        $validator = Validator::make($request->all(), [
            'first_name'   => 'sometimes|string|max:80',
            'last_name'    => 'nullable|string|max:80',
            'middle_name'  => 'nullable|string|max:80',
            'owner_type'   => ['sometimes', Rule::in([Owner::TYPE_INDIVIDUAL, Owner::TYPE_COMPANY])],
            'company_name' => 'nullable|string|max:180|required_if:owner_type,company',
            'tin'          => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:120',
        ], [
            'company_name.required_if' => 'Для юрлица укажите название компании.',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $owner->update($validator->validated());

        return $this->success(new OwnerResource($owner->fresh()), 'Профиль обновлён.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $owner = $request->user('owner');

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|max:100|different:current_password',
        ], [
            'new_password.different' => 'Новый пароль должен отличаться от текущего.',
            'new_password.min'       => 'Пароль — не короче 6 символов.',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        if (!Hash::check($request->input('current_password'), $owner->password)) {
            return $this->error('Текущий пароль неверен.', 422, [
                'current_password' => ['Текущий пароль неверен.'],
            ]);
        }

        $owner->update([
            'password'            => Hash::make($request->input('new_password')),
            'password_changed_at' => now(),
        ]);

        return $this->success(null, 'Пароль изменён.');
    }

    public function requestPhoneChange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:30',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $phone = $request->input('phone');

        if (!PhoneNormalizer::isValid($phone)) {
            return $this->error('Validation error', 422, ['phone' => ['Введите корректный номер телефона.']]);
        }

        $normalized = PhoneNormalizer::normalize($phone);

        if (Owner::where('phone', $normalized)->where('id', '!=', $request->user('owner')->id)->exists()) {
            return $this->error('Этот номер уже привязан к другому аккаунту.', 422, [
                'phone' => ['Номер занят.'],
            ]);
        }

        $limit = $this->otp->checkRateLimit($phone, $request->ip());

        if (!$limit['allowed']) {
            return $this->error("Слишком много запросов. Повторите через {$limit['seconds']} сек.", 429);
        }

        $issued = $this->otp->issue($phone, OwnerOtpCode::PURPOSE_PHONE_CHANGE, $request->ip());

        if (!$issued['sent']) {
            return $this->error('Не удалось отправить SMS. Попробуйте позже.', 503);
        }

        return $this->success([
            'expires_in' => (int) config('otp.ttl_seconds'),
            'delivery'   => $this->otp->isStubMode() ? 'stub' : 'sms',
            'stub_code'  => $issued['stub_code'],
        ]);
    }

    public function confirmPhoneChange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:30',
            'code'  => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $result = $this->otp->verify(
            $request->input('phone'),
            $request->input('code'),
            OwnerOtpCode::PURPOSE_PHONE_CHANGE
        );

        if (!$result['ok']) {
            return $this->error($result['error'], 422, ['code' => [$result['error']]]);
        }

        $owner = $request->user('owner');
        $owner->update([
            'phone'             => PhoneNormalizer::normalize($request->input('phone')),
            'phone_verified_at' => now(),
        ]);

        return $this->success(new OwnerResource($owner->fresh()), 'Номер телефона изменён.');
    }
}
