<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявка помнит клиента, который её оставил (null у заявок до аккаунтов
 * и у созданных менеджером вручную). Без внешнего ключа — по той же причине,
 * что и в client_favorites.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rental_applications', 'client_id')) {
            return;
        }

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rental_applications', 'client_id')) {
            return;
        }

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
