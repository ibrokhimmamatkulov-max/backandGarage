<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Personal access клиент Passport — нужен для входа менеджера по SMS-коду.
 *
 * Вход по паролю идёт через password grant (клиент из
 * 2026_09_26_000002). У входа по коду пароля нет, поэтому токен выдаётся
 * через $user->createToken(), а он без personal access клиента падает с
 * "Personal access client not found". Секрет генерируется здесь же: снаружи
 * он не нужен — PersonalAccessTokenFactory читает его прямо из таблицы
 * (хеширование секретов клиентов в проекте не включено).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('oauth_personal_access_clients')->exists()) {
            return;
        }

        $clientId = DB::table('oauth_clients')->insertGetId([
            'user_id'                => null,
            'name'                   => 'Garage Admin Personal Access',
            'secret'                 => Str::random(40),
            'provider'               => 'users',
            'redirect'               => 'http://localhost',
            'personal_access_client' => true,
            'password_client'        => false,
            'revoked'                => false,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::table('oauth_personal_access_clients')->insert([
            'client_id'  => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $clientIds = DB::table('oauth_clients')
            ->where('name', 'Garage Admin Personal Access')
            ->pluck('id');

        DB::table('oauth_personal_access_clients')->whereIn('client_id', $clientIds)->delete();
        DB::table('oauth_clients')->whereIn('id', $clientIds)->delete();
    }
};
