<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users теперь хранит менеджеров/админов самого Гаража, а не таксопарка —
 * AuthController::login() раньше проверял логин в чужой БД mysql_taxi,
 * которой на Render просто нет. Колонки под то, что читает getAuthData().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login')->nullable()->unique()->after('id');
            $table->string('first_name')->nullable()->after('login');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('patronymic')->nullable()->after('last_name');
            $table->string('phone')->nullable()->after('patronymic');
            $table->boolean('status')->default(true)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login', 'first_name', 'last_name', 'patronymic', 'phone', 'status']);
        });
    }
};
