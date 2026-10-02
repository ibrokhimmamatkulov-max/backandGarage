<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Избранное клиента.
 *
 * Внешнего ключа на performer_transports нет сознательно: таблица
 * объявлений пришла из старого проекта, и тип её id на боевой базе может
 * не совпасть с bigint unsigned — тогда миграция упала бы на несовместимых
 * колонках. Исчезнувшее объявление не ломает список: страница избранного
 * показывает его как «больше не доступно».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_favorites')) {
            return;
        }

        Schema::create('client_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('performer_transport_id');
            $table->timestamps();

            $table->unique(['client_id', 'performer_transport_id'], 'client_favorites_unique');
            $table->index('performer_transport_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_favorites');
    }
};
