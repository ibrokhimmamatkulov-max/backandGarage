<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Не "roles" — та таблица принадлежит Spatie\Permission (система прав
        // менеджеров/Position), название совпало случайно и ломало миграцию
        // на чистой базе ("relation roles already exists").
        if (!Schema::hasTable('app_roles')) {
                Schema::create('app_roles', function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->unique();
                    $table->string('description')->nullable();
                    $table->timestamps();
                });
            }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_roles');
    }
};