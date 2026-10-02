<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class LandingApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'nullable|string|max:255',
            'phone'     => ['required', 'string', 'regex:/^(\+?992|0)?[0-9]{9}$/'],
            // Код подтверждения телефона: отсекает выдуманные номера.
            // Вошедшему клиенту (токен на тот же номер) код не нужен —
            // это проверяет контроллер.
            'code'      => ['nullable', 'string', 'max:10'],
            'city_id'   => ['required', 'integer', Rule::exists('cities', 'id')],
            'offer_id'  => ['required', 'integer', Rule::exists('performer_transports', 'id')],
            'tariff_id' => ['nullable', 'integer', Rule::exists('rental_tariffs', 'id')],
            'comment'   => 'nullable|string|max:1000',

            // Желаемые даты аренды. Ничего не бронируют — это данные лида,
            // по которым считается ориентировочная стоимость (ТЗ §2).
            'desired_start_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'desired_end_date'   => 'nullable|date_format:Y-m-d|after:desired_start_date|required_with:desired_start_date',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required'    => 'Введите код из SMS.',
            'phone.required'   => 'Укажите номер телефона.',
            'phone.regex'      => 'Некорректный номер телефона. Введите номер в формате 992XXXXXXXXX.',
            'city_id.required' => 'Выберите город.',
            'city_id.exists'   => 'Выбранный город не найден.',
            'offer_id.required'=> 'Не указано объявление.',
            'offer_id.exists'  => 'Объявление не найдено или недоступно.',
            'tariff_id.exists' => 'Выбранный тариф не найден.',
            'desired_end_date.after'         => 'Дата окончания должна быть позже даты начала.',
            'desired_end_date.required_with' => 'Укажите дату окончания аренды.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'code'    => 422,
                'message' => 'Ошибка валидации.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
