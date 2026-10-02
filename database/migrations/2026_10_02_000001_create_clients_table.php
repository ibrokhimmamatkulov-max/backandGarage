<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клиенты — арендаторы, которые оставили заявку или вошли по номеру.
 *
 * Отдельно от owners (арендодатели) и users (сотрудники): у них разные
 * контуры входа, и человек может быть и владельцем, и арендатором.
 * Пароля нет — вход только по коду из SMS; номер и есть идентификатор.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients')) {
            return;
        }

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('name', 80)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
