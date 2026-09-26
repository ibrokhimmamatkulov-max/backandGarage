<?php

namespace App\Http\Resources\Landing;

use App\Http\Resources\Listing\ListingTermsResource;
use App\Models\PerformerTransport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OfferDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isGeneral = $this->listing_type === PerformerTransport::TYPE_GENERAL;

        return [
            'id'           => $this->id,
            'listing_type' => $this->listing_type,
            'title'        => $this->title,
            'description'  => $this->description,
            'brand'        => $this->model_car?->brand?->name,
            'model'        => $this->model_car?->car_model,
            'year'         => $this->year_of_issue,
            'count_seat'   => $this->count_seat,
            'dop_info'     => $this->dop_info,
            'address'      => $this->address,
            'min_rent_days'=> $this->min_rent_days,
            'max_rent_days'=> $this->max_rent_days,

            // Технические поля заполняются владельцем при подаче, но до сих пор
            // никуда не доходили: карточка показывала только коробку и топливо.
            'customs_cleared'  => $this->customs_cleared !== null ? (bool) $this->customs_cleared : null,
            'engine_volume'    => $this->engine_volume !== null ? (float) $this->engine_volume : null,
            'mileage'          => $this->mileage !== null ? (int) $this->mileage : null,
            'drive_type'       => $this->drive_type,
            'has_taxi_license' => (bool) $this->has_taxi_license,
            'has_turbo'        => (bool) $this->has_turbo,
            'has_gps_tracker'  => (bool) $this->has_gps_tracker,
            // Отметку ставит менеджер по снимкам техпаспорта; сами документы
            // в объявлении не показываются (ТЗ §4).
            'vin_verified'     => (bool) $this->vin_verified,
            'city'         => [
                'id'   => $this->city?->id,
                'name' => $this->city?->name,
            ],
            'gearbox'      => [
                'id'   => $this->gearbox?->id,
                'name' => $this->gearbox?->name,
            ],
            'body_type'    => [
                'id'   => $this->body_type?->id,
                'name' => $this->body_type?->name,
            ],
            'color'        => [
                'id'   => $this->color?->id,
                'name' => $this->color?->name,
            ],
            'fuel_type'    => [
                'id'   => $this->fuel_type?->id,
                'name' => $this->fuel_type?->name,
            ],
            'dop_options'  => $this->dopOptions->map(fn ($opt) => [
                'id'   => $opt->car_option?->id,
                'name' => $opt->car_option?->name,
            ])->values(),
            'photos'       => $this->photos->map(fn ($p) => Storage::url($p->path))->values(),

            'min_price'    => $this->min_price !== null ? (float) $this->min_price : null,

            'price_tiers'  => $isGeneral
                ? $this->priceTiers->map(fn ($t) => [
                    'id'            => $t->id,
                    'min_days'      => (int) $t->min_days,
                    'max_days'      => $t->max_days !== null ? (int) $t->max_days : null,
                    'price_per_day' => (float) $t->price_per_day,
                ])->values()
                : [],

            // Тариф под такси с 25.09.2026 — один на объявление, не список.
            'taxi_tariff'  => $this->taxiTariff ? [
                'min_months'         => $this->taxiTariff->min_months,
                'off_days_per_month' => $this->taxiTariff->off_days_per_month,
                'price_per_day'      => (float) $this->taxiTariff->price_per_day,
                'monthly_total'      => $this->taxiTariff->monthly_total,
            ] : null,

            // Старая таксопарковая схема — только для записей до 25.09.2026.
            'tariffs'      => $this->tariffs->map(fn ($t) => [
                'id'              => $t->id,
                'duration_days'   => $t->duration_days,
                'price'           => $t->price,
                'free_weekend_day'=> (int) $t->free_weekend_day,
            ])->values(),

            'terms' => $this->terms ? new ListingTermsResource($this->terms) : null,

            // Занятые даты — чтобы карточка могла подсветить их в календаре.
            // Бронирования это не создаёт: заявка на занятые даты принимается (ТЗ §2).
            'unavailable_periods' => $this->unavailablePeriods->map(fn ($p) => [
                'date_from' => $p->date_from?->toDateString(),
                'date_to'   => $p->date_to?->toDateString(),
            ])->values(),

            'owner' => $this->owner ? [
                'id'           => $this->owner->id,
                'display_name' => $this->owner->display_name,
                'owner_type'   => $this->owner->owner_type,
            ] : null,

            'performer_id' => $this->performer_id,
        ];
    }
}
