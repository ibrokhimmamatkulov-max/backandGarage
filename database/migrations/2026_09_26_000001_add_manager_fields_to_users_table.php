<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users теперь хранит менеджеров/админов самого Гаража, а не таксопарка —
 * AuthController::login() раньше проверял логин в чужой БД mysql_taxi,
 * которой на Render просто нет. Колонки под то, что читает getAuthData().
 *
 * Каждая колонка добавляется отдельным Schema::table() под своей проверкой
 * hasColumn: на Render entrypoint.sh перезапускался (контейнер
 * рестартовал до того, как этот прогон migrate успел дойти до конца),
 * и колонки оказались добавлены в базу, а миграция — не отмечена
 * выполненной, из-за чего повторный прогон падал на "column already
 * exists". Один Schema::table() с несколькими add column собирается
 * Laravel в один ALTER TABLE, поэтому проверять нужно каждую колонку
 * отдельно, а не только первую.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'login'      => fn (Blueprint $table) => $table->string('login')->nullable()->unique(),
            'first_name' => fn (Blueprint $table) => $table->string('first_name')->nullable(),
            'last_name'  => fn (Blueprint $table) => $table->string('last_name')->nullable(),
            'patronymic' => fn (Blueprint $table) => $table->string('patronymic')->nullable(),
            'phone'      => fn (Blueprint $table) => $table->string('phone')->nullable(),
            'status'     => fn (Blueprint $table) => $table->boolean('status')->default(true),
        ];

        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('users', $name)) {
                Schema::table('users', fn (Blueprint $table) => $add($table));
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['login', 'first_name', 'last_name', 'patronymic', 'phone', 'status'] as $name) {
                if (Schema::hasColumn('users', $name)) {
                    $table->dropColumn($name);
                }
            }
        });
    }
};
