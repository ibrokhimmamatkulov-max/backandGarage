<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Клиент (арендатор): оставил заявку или вошёл по номеру и коду из SMS.
 *
 * Как и Owner, намеренно не App\Models\User — та живёт под сотрудников.
 */
class Client extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'phone',
        'name',
        'phone_verified_at',
        'last_login_at',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
    ];

    public function favorites(): HasMany
    {
        return $this->hasMany(ClientFavorite::class);
    }
}
