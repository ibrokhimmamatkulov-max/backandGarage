<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RentalApplication extends Model
{
    use HasFactory;

    public const CHANGED_BY_OWNER = 'owner';
    public const CHANGED_BY_MANAGER = 'manager';

    protected $fillable = [
        'performer_transport_id',
        'rental_tariff_id',
        'user_id',
        'name',
        'phone',
        'city_id',
        'comment',
        'promo_code',
        'status_id',
        // Гараж 2.0
        'owner_id',
        'desired_start_date',
        'desired_end_date',
        'price_tier_id',
        'calculated_total',
        'source',
        'status_changed_by',
        'status_changed_at',
        'client_id',
    ];

    protected $casts = [
        'desired_start_date' => 'date',
        'desired_end_date'   => 'date',
        'calculated_total'   => 'decimal:2',
        'status_changed_at'  => 'datetime',
    ];

    public function car()
    {
        return $this->belongsTo(PerformerTransport::class, 'performer_transport_id');
    }

    public function tariff()
    {
        return $this->belongsTo(RentalTariff::class, 'rental_tariff_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function status()
    {
        return $this->belongsTo(ApplicationStatus::class, 'status_id');
    }

    public function owner()
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function priceTier()
    {
        return $this->belongsTo(RentalPriceTier::class, 'price_tier_id');
    }

    public function scopeOwnedBy($query, int $ownerId)
    {
        return $query->where('rental_applications.owner_id', $ownerId);
    }
}
