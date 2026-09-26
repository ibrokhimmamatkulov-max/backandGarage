<?php

namespace App\Http\Resources\Listing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Объявление глазами его владельца: со статусом, причиной отклонения
 * и счётчиками — то, чего нет в публичной карточке.
 */
class OwnerListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'description'       => $this->description,
            'listing_type'      => $this->listing_type,
            'moderation_status' => $this->moderation_status,
            'rejection_reason'  => $this->rejection_reason,
            'published_at'      => $this->published_at?->toIso8601String(),
            'boosted_until'     => $this->boosted_until?->toIso8601String(),
            'submitted_at'      => $this->submitted_at?->toIso8601String(),
            'views_count'       => (int) $this->views_count,
            'applications_count' => $this->whenCounted('applications'),

            'brand'         => $this->model_car?->brand?->name,
            // Марка выбирается в форме отдельно от модели, а хранится только
            // car_model_id — без её id форму правки нечем заполнить.
            'brand_id'      => $this->model_car?->brand?->id,
            'model'         => $this->model_car?->car_model,
            'car_model_id'  => $this->car_model_id,
            'year'          => $this->year_of_issue,
            'car_number'    => $this->car_number,
            'count_seat'    => $this->count_seat,
            'address'       => $this->address,
            'dop_info'      => $this->dop_info,
            'min_rent_days' => $this->min_rent_days,
            'max_rent_days' => $this->max_rent_days,

            // Всё, что принимает ListingRequest, обязано и возвращаться:
            // форма редактирования заполняется из этого ответа, и поле,
            // которого здесь нет, она затрёт пустым значением.
            'condition_id'     => $this->condition_id,
            'customs_cleared'  => $this->customs_cleared !== null ? (bool) $this->customs_cleared : null,
            'engine_volume'    => $this->engine_volume !== null ? (float) $this->engine_volume : null,
            'mileage'          => $this->mileage !== null ? (int) $this->mileage : null,
            'drive_type'       => $this->drive_type,
            'has_taxi_license' => (bool) $this->has_taxi_license,
            'has_turbo'        => (bool) $this->has_turbo,
            'has_gps_tracker'  => (bool) $this->has_gps_tracker,
            'VIN'              => $this->VIN,

            // Только для показа: отметку ставит менеджер, владелец её не меняет
            'vin_verified'     => (bool) $this->vin_verified,

            'city'      => $this->relationOrNull($this->city),
            'gearbox'   => $this->relationOrNull($this->gearbox),
            'body_type' => $this->relationOrNull($this->body_type),
            'color'     => $this->relationOrNull($this->color),
            'fuel_type' => $this->relationOrNull($this->fuel_type),

            'min_price'   => $this->min_price !== null ? (float) $this->min_price : null,
            'price_tiers' => PriceTierResource::collection($this->whenLoaded('priceTiers')),
            'terms'       => $this->whenLoaded('terms', fn () => new ListingTermsResource($this->terms)),

            // Тариф аренды под такси — форма правки заполняется отсюда,
            // как и остальные разделы выше.
            'tariff' => $this->whenLoaded('taxiTariff', fn () => $this->taxiTariff ? [
                'min_months'         => $this->taxiTariff->min_months,
                'off_days_per_month' => $this->taxiTariff->off_days_per_month,
                'price_per_day'      => (float) $this->taxiTariff->price_per_day,
                'monthly_total'      => $this->taxiTariff->monthly_total,
            ] : null),

            'dop_options' => $this->whenLoaded('dopOptions', fn () => $this->dopOptions
                ->map(fn ($o) => ['id' => $o->car_option?->id, 'name' => $o->car_option?->name])
                ->filter(fn ($o) => $o['id'] !== null)
                ->values()),

            'unavailable_periods' => $this->whenLoaded(
                'unavailablePeriods',
                fn () => $this->unavailablePeriods->map(fn ($p) => [
                    'id'        => $p->id,
                    'date_from' => $p->date_from?->toDateString(),
                    'date_to'   => $p->date_to?->toDateString(),
                    'comment'   => $p->comment,
                ])
            ),

            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id'  => $p->id,
                'url' => Storage::url($p->path),
            ])),
        ];
    }

    private function relationOrNull($relation): ?array
    {
        return $relation ? ['id' => $relation->id, 'name' => $relation->name] : null;
    }
}
