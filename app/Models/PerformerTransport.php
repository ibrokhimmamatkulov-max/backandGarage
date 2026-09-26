<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class PerformerTransport extends BasicModel
{
    use SoftDeletes;

    public $table = 'performer_transports';
    public const ACTIVE_CONNECTION = 1;
    public const WAITING_CONNECTION = 2;
    public const DELETED = 3;
    public const ACTIVE = 1;
    public const DISABLED = 0;

    public const CARGO = 3;

    // --- Объявление (Гараж 2.0) ---
    public const TYPE_TAXI = 'taxi';
    public const TYPE_GENERAL = 'general';

    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_OWNER = 'owner';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Поля, изменение которых у опубликованного объявления возвращает его
     * на повторную модерацию. Описание, фото и календарь сюда не входят.
     */
    public const SIGNIFICANT_FIELDS = [
        'car_model_id', 'body_type_id', 'year_of_issue', 'car_number',
        'city_id', 'gearbox_id', 'fuel_type_id', 'count_seat', 'min_rent_days',
        'max_rent_days', 'listing_type',
    ];

    protected $fillable = [
        'performer_id',
        'car_model_id',
        'body_type_id',
        'condition_id',
        'color_id',
        'dop_info',
        'year_of_issue',
        'count_seat',
        'car_number',
        'connected_id',
        'active',
        'fuel_type_id',
        'city_id',
        'gearbox_id',
        'min_rent_days',
        'address',
        // Гараж 2.0
        'owner_id',
        'listing_type',
        'source',
        'moderation_status',
        'rejection_reason',
        'published_at',
        'boosted_until',
        'submitted_at',
        'title',
        'description',
        'max_rent_days',
        // Технические поля
        'customs_cleared',
        'engine_volume',
        'mileage',
        'drive_type',
        'has_taxi_license',
        'has_turbo',
        'has_gps_tracker',
        'vin_verified',
        'vin_verified_at',
        'VIN',
    ];

    protected $casts = [
        'published_at'     => 'datetime',
        'boosted_until'    => 'datetime',
        'submitted_at'     => 'datetime',
        'vin_verified_at'  => 'datetime',
        'min_rent_days'    => 'integer',
        'max_rent_days'    => 'integer',
        'views_count'      => 'integer',
        'mileage'          => 'integer',
        'engine_volume'    => 'float',
        'customs_cleared'  => 'boolean',
        'has_taxi_license' => 'boolean',
        'has_turbo'        => 'boolean',
        'has_gps_tracker'  => 'boolean',
        'vin_verified'     => 'boolean',
    ];

    public function model_car() {
        return $this->belongsTo(Marka::class, 'car_model_id', 'id');
    }
    public function body_type() {
        return $this->belongsTo(BodyType::class, 'body_type_id', 'id');
    }
    public function color() {
        return $this->belongsTo(ColorCar::class, 'color_id', 'id');
    }
    public function condition() {
        return $this->belongsTo(CarCondition::class, 'condition_id', 'id');
    }
    public function car_connection() {
        return $this->belongsTo(CarConnected::class, 'connected_id', 'id');
    }
    public function car_drivers() {
        return $this->belongsToMany(Performer::class, DriverCar::class, 'car_id', 'driver_id')->where('is_attached', 1);
    }

    public function performer_info()
    {
        return $this->belongsTo(Performer::class, 'performer_id', 'id');
    }

    public function  created_user() {
        return $this->belongsTo(User::class,'created_user_id', 'id');
    }
    public function  updated_user() {
        return $this->belongsTo(User::class,'updated_user_id', 'id');
    }

    public function car_options() {
        return $this->hasMany(PerformerTransportOption::class, 'performer_transport_id', 'id')->with('car_option');
    }

    public function dopOptions()
    {
        return $this->hasMany(PerformerTransportOption::class, 'performer_transport_id', 'id')
                    ->whereNotNull('option_id')
                    ->whereHas('car_option', function ($query) {
                        $query->whereNull('model');
                    })
                    ->with('car_option'); 
    }

    public function fuel_type()
    {
        return $this->belongsTo(CarOption::class, 'fuel_type_id','id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function gearbox()
    {
        return $this->belongsTo(Gearbox::class);
    }

    public function photos() {
        return $this->hasMany(PerformerTransportPhoto::class);
    }
    
    public function tariffs() {
        return $this->belongsToMany(RentalTariff::class, 'car_rental_tariff', 'performer_transport_id', 'rental_tariff_id')
            ->withTimestamps();
    }

    public function rental_aplication() {
        return $this->belongsTo(RentalApplication::class);
    }

    // ------------------------------------------------------------------
    // Гараж 2.0: объявление
    // ------------------------------------------------------------------

    public function owner()
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function terms()
    {
        return $this->hasOne(ListingTerms::class, 'performer_transport_id');
    }

    public function priceTiers()
    {
        return $this->hasMany(RentalPriceTier::class, 'performer_transport_id')
            ->orderBy('min_days');
    }

    /**
     * Тариф аренды под такси — с 25.09.2026 единственный способ подачи.
     * priceTiers() остаётся только для чтения старых объявлений типа
     * general, форма подачи их больше не создаёт.
     */
    public function taxiTariff()
    {
        return $this->hasOne(TaxiTariff::class, 'performer_transport_id');
    }

    public function unavailablePeriods()
    {
        return $this->hasMany(ListingUnavailablePeriod::class, 'performer_transport_id')
            ->orderBy('date_from');
    }

    public function applications()
    {
        return $this->hasMany(RentalApplication::class, 'performer_transport_id');
    }

    public function moderationLogs()
    {
        return $this->hasMany(ListingModerationLog::class, 'performer_transport_id')
            ->orderByDesc('id');
    }

    public function documents()
    {
        return $this->hasMany(OwnerDocument::class, 'performer_transport_id');
    }

    /**
     * Единственное определение того, что видно на витрине.
     * Любая публичная выборка должна проходить через этот scope.
     */
    public function scopeVisibleOnShowcase($query)
    {
        return $query
            ->where('performer_transports.moderation_status', self::STATUS_PUBLISHED)
            ->where(function ($q) {
                // Срок объявления истёк (config('listing.lifetime_days'), 30 по
                // умолчанию) — прячем немедленно, не дожидаясь команды listings:expire.
                // Без published_at (легаси-записи) правило не применяется: сравнивать не с чем.
                $q->whereNull('performer_transports.published_at')
                  ->orWhere(
                      'performer_transports.published_at',
                      '>=',
                      now()->subDays((int) config('listing.lifetime_days', 30))
                  );
            })
            ->where(function ($q) {
                // active — легаси-флаг, у части старых строк он NULL.
                $q->where('performer_transports.active', self::ACTIVE)
                  ->orWhereNull('performer_transports.active');
            })
            ->where(function ($q) {
                $q->whereNull('performer_transports.owner_id')
                  ->orWhereExists(function ($sub) {
                      $sub->selectRaw(1)
                          ->from('owners')
                          ->whereColumn('owners.id', 'performer_transports.owner_id')
                          ->where('owners.status', Owner::STATUS_ACTIVE)
                          ->whereNull('owners.deleted_at');
                  });
            });
    }

    public function scopeOwnedBy($query, int $ownerId)
    {
        return $query->where('performer_transports.owner_id', $ownerId);
    }

    public function isGeneral(): bool
    {
        return $this->listing_type === self::TYPE_GENERAL;
    }

    public function isPublished(): bool
    {
        return $this->moderation_status === self::STATUS_PUBLISHED;
    }

    /**
     * Минимальная цена за сутки — для карточки и сортировки.
     * У general берётся из ступеней, у taxi — из старых тарифов.
     */
    public function getMinPriceAttribute()
    {
        if ($this->isGeneral()) {
            return $this->relationLoaded('priceTiers')
                ? $this->priceTiers->min('price_per_day')
                : $this->priceTiers()->min('price_per_day');
        }

        // С 25.09.2026 — новый тариф под такси, один на объявление.
        // Старая связь tariffs() (car_rental_tariff) остаётся запасным
        // путём для записей, созданных до этой даты.
        $taxiTariff = $this->relationLoaded('taxiTariff') ? $this->taxiTariff : $this->taxiTariff()->first();
        if ($taxiTariff) {
            return (float) $taxiTariff->price_per_day;
        }

        return $this->relationLoaded('tariffs')
            ? $this->tariffs->min('price')
            : $this->tariffs()->min('price');
    }
}
