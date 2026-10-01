<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagerOtpCode extends Model
{
    public const PURPOSE_AUTH = 'auth';

    /** Привязка телефона к учётке менеджера после входа по паролю. */
    public const PURPOSE_PHONE_BIND = 'phone_bind';

    public const MAX_ATTEMPTS = 5;

    protected $table = 'manager_otp_codes';

    protected $fillable = [
        'phone',
        'code_hash',
        'purpose',
        'expires_at',
        'attempts',
        'consumed_at',
        'ip',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
        'attempts'    => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
