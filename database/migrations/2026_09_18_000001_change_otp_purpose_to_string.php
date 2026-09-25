<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * purpose из enum в строку.
 *
 * Появилось назначение «подтверждение телефона в заявке» — оно не про вход
 * владельца и аккаунта не создаёт. Каждое новое назначение упиралось бы
 * в миграцию enum, поэтому колонка становится строкой: список назначений
 * живёт константами в модели.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('owner_otp_codes')) {
            return;
        }

        // Меняем тип сырым SQL: doctrine/dbal для enum в проекте нет
        if (DB::connection()->getDriverName() === 'pgsql') {
            // На Postgres enum() из Blueprint — это varchar + check-констрейнт
            // с именем по конвенции Laravel: {table}_{column}_check.
            DB::statement('ALTER TABLE owner_otp_codes DROP CONSTRAINT IF EXISTS owner_otp_codes_purpose_check');
            DB::statement("ALTER TABLE owner_otp_codes ALTER COLUMN purpose TYPE VARCHAR(32), ALTER COLUMN purpose SET DEFAULT 'auth', ALTER COLUMN purpose SET NOT NULL");

            return;
        }

        DB::statement("ALTER TABLE `owner_otp_codes` MODIFY `purpose` VARCHAR(32) NOT NULL DEFAULT 'auth'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('owner_otp_codes')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE owner_otp_codes ALTER COLUMN purpose SET DEFAULT 'auth'");
            DB::statement("ALTER TABLE owner_otp_codes ADD CONSTRAINT owner_otp_codes_purpose_check CHECK (purpose IN ('auth','phone_change','password_reset'))");

            return;
        }

        DB::statement(
            "ALTER TABLE `owner_otp_codes` MODIFY `purpose` "
            . "ENUM('auth','phone_change','password_reset') NOT NULL DEFAULT 'auth'"
        );
    }
};
