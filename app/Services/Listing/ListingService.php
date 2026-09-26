<?php

namespace App\Services\Listing;

use App\Models\CarOption;
use App\Models\ListingTerms;
use App\Models\Owner;
use App\Models\PerformerTransport;
use App\Models\PerformerTransportOption;
use App\Models\TaxiTariff;
use App\Models\VehicleVin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Создание и обновление объявления одной транзакцией:
 * машина + условия + тариф. Частично созданное объявление —
 * худший из возможных исходов, поэтому всё или ничего.
 *
 * С 25.09.2026 платформа целиком про аренду под такси: listing_type у
 * новых объявлений всегда TYPE_TAXI, «ступеней цены» общей посуточной
 * аренды форма подачи больше не собирает. RentalPriceTier и
 * PriceTierValidator остаются в коде ради старых записей типа general —
 * их читает публичная карточка, но создавать такие через эту форму
 * больше нельзя.
 */
class ListingService
{
    private const CAR_FIELDS = [
        'car_model_id', 'body_type_id', 'condition_id', 'color_id', 'year_of_issue',
        'count_seat', 'car_number', 'fuel_type_id', 'city_id', 'gearbox_id',
        'min_rent_days', 'max_rent_days', 'address', 'title', 'description', 'dop_info',
        'customs_cleared', 'engine_volume', 'mileage', 'drive_type',
        'has_taxi_license', 'has_turbo', 'has_gps_tracker', 'VIN',
    ];

    public function create(Owner $owner, array $data): PerformerTransport
    {
        $this->assertPlateIsFree($data['car_number'] ?? null);

        // Премодерация отключена решением заказчика: объявление выходит сразу,
        // менеджер смотрит его постфактум в админке (см. config/listing.php).
        $autoPublish = (bool) config('listing.auto_publish', true);

        return DB::transaction(function () use ($owner, $data, $autoPublish) {
            $listing = PerformerTransport::create(
                $this->carAttributes($data) + [
                    'owner_id'          => $owner->id,
                    'listing_type'      => PerformerTransport::TYPE_TAXI,
                    'source'            => PerformerTransport::SOURCE_OWNER,
                    'moderation_status' => $autoPublish
                        ? PerformerTransport::STATUS_PUBLISHED
                        : PerformerTransport::STATUS_PENDING,
                    'submitted_at'      => now(),
                    'published_at'      => $autoPublish ? now() : null,
                    'active'            => PerformerTransport::ACTIVE,
                ]
            );

            $this->syncTerms($listing, $data['terms'] ?? []);
            $this->syncTariff($listing, $data['tariff']);
            $this->syncDopOptions($listing, $data['dop_options'] ?? []);
            $this->recordVin($listing, $data['VIN'] ?? null, $owner->id);

            return $listing->load(['terms', 'taxiTariff', 'dopOptions.car_option']);
        });
    }

    /**
     * @return array{listing: PerformerTransport, returned_to_moderation: bool}
     */
    public function update(PerformerTransport $listing, array $data): array
    {
        if (!empty($data['car_number'])) {
            $this->assertPlateIsFree($data['car_number'], $listing->id);
        }

        return DB::transaction(function () use ($listing, $data) {
            $attributes = $this->carAttributes($data);
            $significant = $this->touchesSignificantFields($listing, $attributes, $data);

            $listing->fill($attributes);

            // При включённой премодерации правка существенных полей возвращает
            // объявление на проверку. При автопубликации оно остаётся на витрине:
            // блокировать владельца из-за смены цены смысла нет.
            $returned = false;
            if (
                $significant
                && !config('listing.auto_publish', true)
                && $listing->moderation_status === PerformerTransport::STATUS_PUBLISHED
            ) {
                $listing->moderation_status = PerformerTransport::STATUS_PENDING;
                $listing->submitted_at = now();
                $returned = true;
            }

            $listing->save();

            if (array_key_exists('terms', $data)) {
                $this->syncTerms($listing, $data['terms'] ?? []);
            }

            if (array_key_exists('tariff', $data)) {
                $this->syncTariff($listing, $data['tariff']);
            }

            if (array_key_exists('dop_options', $data)) {
                $this->syncDopOptions($listing, $data['dop_options'] ?? []);
            }

            if (!empty($data['VIN'])) {
                $this->recordVin($listing, $data['VIN'], $listing->owner_id);
            }

            return [
                'listing'                => $listing->load(['terms', 'taxiTariff']),
                'returned_to_moderation' => $returned,
            ];
        });
    }

    /**
     * Готово ли объявление к публикации (ТЗ §9.2).
     *
     * @return array<int, string>
     */
    public function publishBlockers(PerformerTransport $listing): array
    {
        $blockers = [];

        if ($listing->photos()->count() === 0) {
            $blockers[] = 'Добавьте хотя бы одну фотографию.';
        }

        // isGeneral() — только для старых записей до 25.09.2026, новые всегда taxi.
        if ($listing->isGeneral()) {
            if ($listing->priceTiers()->count() === 0) {
                $blockers[] = 'Задайте хотя бы одну ступень цены.';
            }
        } elseif (!$listing->taxiTariff) {
            $blockers[] = 'Задайте тариф аренды.';
        }

        return $blockers;
    }

    private function carAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(self::CAR_FIELDS));
    }

    private function touchesSignificantFields(PerformerTransport $listing, array $attributes, array $data): bool
    {
        foreach (PerformerTransport::SIGNIFICANT_FIELDS as $field) {
            if (array_key_exists($field, $attributes)
                && (string) $attributes[$field] !== (string) $listing->getOriginal($field)) {
                return true;
            }
        }

        // Тариф и условия — тоже существенные.
        return array_key_exists('tariff', $data) || array_key_exists('terms', $data);
    }

    private function syncTerms(PerformerTransport $listing, array $terms): void
    {
        ListingTerms::updateOrCreate(
            ['performer_transport_id' => $listing->id],
            $terms
        );
    }

    /** Один тариф на объявление — updateOrCreate, а не список, как раньше у ступеней цены */
    private function syncTariff(PerformerTransport $listing, array $tariff): void
    {
        TaxiTariff::updateOrCreate(
            ['performer_transport_id' => $listing->id],
            [
                'min_months'         => $tariff['min_months'],
                'off_days_per_month' => $tariff['off_days_per_month'],
                'price_per_day'      => $tariff['price_per_day'],
            ]
        );
    }

    /**
     * Доп. опции хранятся как набор строк в pivot-таблице, а не M:N-связь
     * напрямую: у PerformerTransportOption есть свои is_check и SoftDeletes.
     * Простое delete+create — тот же приём, что и у тарифа.
     */
    private function syncDopOptions(PerformerTransport $listing, array $optionIds): void
    {
        PerformerTransportOption::where('performer_transport_id', $listing->id)
            ->whereIn('option_id', CarOption::query()->whereNull('model')->pluck('id'))
            ->delete();

        foreach (array_unique($optionIds) as $optionId) {
            PerformerTransportOption::create([
                'performer_transport_id' => $listing->id,
                'option_id'              => $optionId,
                'is_check'               => true,
            ]);
        }
    }

    /**
     * Реестр VIN живёт отдельно от объявления (см. миграцию
     * vehicle_vins) — переживает архивацию и повторное выставление
     * машины под другим аккаунтом. Статус битый/небитый record() не
     * трогает: его выставляют отдельно, подача объявления не должна
     * случайно сбросить уже известную отметку.
     */
    private function recordVin(PerformerTransport $listing, ?string $vin, ?int $ownerId): void
    {
        if (!$vin) {
            return;
        }

        VehicleVin::record($vin, $listing->id, $ownerId);
    }

    /**
     * Одну машину нельзя выставить дважды (ТЗ §9.3).
     * Архивные и удалённые объявления номер не занимают.
     */
    private function assertPlateIsFree(?string $plate, ?int $exceptId = null): void
    {
        if (!$plate) {
            return;
        }

        $taken = PerformerTransport::query()
            ->where('car_number', $plate)
            ->whereNotIn('moderation_status', [PerformerTransport::STATUS_ARCHIVED])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'car_number' => ['Машина с таким госномером уже размещена. '
                    . 'Если это ваше объявление, найдите его в кабинете; если нет — обратитесь в поддержку.'],
            ]);
        }
    }
}
