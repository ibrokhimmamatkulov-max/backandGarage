<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

class ListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // Шаг 1 — автомобиль
            'city_id'       => "{$required}|integer|exists:cities,id",
            'car_model_id'  => "{$required}|integer|exists:model_cars,id",
            'year_of_issue' => "{$required}|integer|min:1950|max:" . (date('Y') + 1),
            'body_type_id'  => "{$required}|integer|exists:body_types,id",
            'gearbox_id'    => "{$required}|integer|exists:gearboxes,id",
            'fuel_type_id'  => "{$required}|integer|exists:car_options,id",
            'car_number'    => "{$required}|string|max:30",
            'color_id'      => 'nullable|integer|exists:colors,id',
            'condition_id'  => 'nullable|integer|exists:car_conditions,id',
            'count_seat'    => 'nullable|integer|min:1|max:60',

            // Доп. опции: кондиционер, камера и т.п. — те же car_options, что
            // показывает карточка объявления, запись раньше нигде не принималась.
            'dop_options'   => 'nullable|array',
            'dop_options.*' => 'integer|exists:car_options,id',

            // Технические поля (часть — специфика Таджикистана). Растаможка,
            // пробег и лицензия на такси перенесены из необязательных в
            // обязательные решением от 26.09.2026 — покупатель должен видеть
            // это сразу, а не после звонка владельцу.
            'customs_cleared'  => "{$required}|boolean",
            'engine_volume'    => 'nullable|numeric|min:0.1|max:9.9',
            'mileage'          => "{$required}|integer|min:0|max:2000000",
            'drive_type'       => 'nullable|in:fwd,rwd,awd',
            'has_taxi_license' => "{$required}|boolean",
            'has_turbo'        => 'nullable|boolean',
            'has_gps_tracker'  => 'nullable|boolean',
            'VIN'              => 'nullable|string|size:17|regex:/^[A-HJ-NPR-Z0-9]{17}$/i',

            // Шаг 4 — описание
            'title'       => 'nullable|string|max:180',
            'description' => 'nullable|string|max:5000',
            'address'     => 'nullable|string|max:255',
            'dop_info'    => 'nullable|string|max:2000',

            // Сроки — раньше был отдельный диапазон в сутках для общей
            // посуточной аренды, поле осталось nullable ради старых записей,
            // форма подачи его больше не заполняет: минимальный срок теперь
            // задаёт сам тариф (tariff.min_months).
            'min_rent_days' => 'nullable|integer|min:1|max:365',
            'max_rent_days' => 'nullable|integer|min:1|max:3650|gte:min_rent_days',

            /*
             * Тариф аренды под такси (решение от 25.09.2026) — единственный
             * вид объявления на платформе, «ступеней цены» общей посуточной
             * аренды больше нет. Один тариф на объявление, не список.
             */
            'tariff'                     => "{$required}|array",
            'tariff.min_months'          => "{$required}|integer|in:3,4,6",
            'tariff.off_days_per_month'  => "{$required}|integer|in:0,2,3,4",
            'tariff.price_per_day'       => "{$required}|numeric|min:1|max:100000",

            // Шаг 3 — условия
            'terms'                          => 'nullable|array',
            'terms.deposit_amount'           => 'nullable|numeric|min:0|max:1000000',
            'terms.deposit_return_policy'    => 'nullable|in:on_return,daily,none',
            'terms.deposit_daily_return'     => 'nullable|numeric|min:0',
            'terms.mileage_limit_per_day'    => 'nullable|integer|min:1|max:10000',
            'terms.overmileage_price'        => 'nullable|numeric|min:0',
            'terms.fuel_policy'              => 'nullable|in:full_to_full,tenant,owner',
            'terms.min_driver_age'           => 'nullable|integer|min:16|max:99',
            'terms.min_driver_experience'    => 'nullable|integer|min:0|max:80',
            'terms.documents_pledge'         => 'nullable|in:none,passport,any_id',
            'terms.require_clean_record'     => 'nullable|boolean',
            'terms.allow_taxi'               => 'nullable|boolean',
            'terms.allow_intercity'          => 'nullable|boolean',
            'terms.allow_abroad'             => 'nullable|boolean',
            'terms.allow_smoking'            => 'nullable|boolean',
            'terms.allow_pets'               => 'nullable|boolean',
            'terms.delivery_available'       => 'nullable|boolean',
            'terms.delivery_price'           => 'nullable|numeric|min:0',
            'terms.additional_terms'         => 'nullable|string|max:3000',
        ];
    }

    public function messages(): array
    {
        return [
            'max_rent_days.gte'   => 'Максимальный срок не может быть меньше минимального.',
            'car_number.required' => 'Укажите госномер — по нему мы не даём выставить одну машину дважды.',

            'customs_cleared.required'  => 'Укажите, растаможен ли автомобиль в РТ.',
            'mileage.required'          => 'Укажите пробег.',
            'has_taxi_license.required' => 'Укажите, есть ли лицензия на такси.',
            'VIN.size'                  => 'VIN — ровно 17 символов.',
            'VIN.regex'                 => 'Неверный формат VIN: латинские буквы и цифры, без I, O, Q.',

            'tariff.required'                    => 'Укажите тариф аренды.',
            'tariff.min_months.required'         => 'Выберите минимальный срок аренды.',
            'tariff.min_months.in'               => 'Минимальный срок — 3, 4 или 6 месяцев.',
            'tariff.off_days_per_month.required' => 'Укажите число выходных в месяц.',
            'tariff.off_days_per_month.in'       => 'Выходных в месяц — 0, 2, 3 или 4.',
            'tariff.price_per_day.required'      => 'Укажите цену за сутки.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('car_number')) {
            $this->merge([
                'car_number' => mb_strtoupper(trim((string) $this->input('car_number'))),
            ]);
        }
    }
}
