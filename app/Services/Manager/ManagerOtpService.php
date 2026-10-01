<?php

namespace App\Services\Manager;

use App\Models\ManagerOtpCode;
use App\Services\Owner\OtpService;

/**
 * Та же логика кодов, что у владельцев (генерация, хеш, срок, попытки,
 * демо-режим, SMS-шлюз), но своя таблица и свои счётчики частоты —
 * запросы кода в кабинет и в админку с одного номера не делят лимит.
 */
class ManagerOtpService extends OtpService
{
    protected function model(): string
    {
        return ManagerOtpCode::class;
    }

    protected function rateKeyPrefix(): string
    {
        return 'manager-otp:';
    }
}
