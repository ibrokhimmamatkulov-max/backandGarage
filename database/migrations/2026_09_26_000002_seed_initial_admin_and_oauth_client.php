<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Первый вход в админку невозможен без учётки. И без password-grant
 * OAuth-клиента: OAuth2Service::token() всегда шлёт client_id/client_secret
 * из PASSPORT_GRANT_CLIENT_ID/SECRET, а oauth_clients в собственной базе
 * Гаража ещё пуст (раньше эти таблицы читались из mysql_taxi).
 *
 * Пароль и секрет клиента читаются из env, а не хардкодятся: репозиторий
 * публичный. Если INITIAL_ADMIN_PASSWORD / PASSPORT_GRANT_CLIENT_SECRET
 * не заданы на момент миграции — сидинг тихо пропускается (чтобы не
 * ронять деплой) и уходит предупреждение в лог; тогда нужно задать
 * переменные на Render и передеплоить, чтобы миграция отработала заново
 * руками (php artisan migrate:rollback --step=1, потом migrate снова)
 * либо просто дописать строки вручную.
 *
 * id=1 у клиента безопасен: на момент этой миграции oauth_clients
 * гарантированно пуст (Passport::hashClientSecrets() не включён, secret
 * хранится как есть, без хеширования).
 */
return new class extends Migration
{
    private const ADMIN_LOGIN = 'admin';

    public function up(): void
    {
        $adminPassword = env('INITIAL_ADMIN_PASSWORD');
        $clientSecret  = env('PASSPORT_GRANT_CLIENT_SECRET');

        if ($adminPassword && !DB::table('users')->where('login', self::ADMIN_LOGIN)->exists()) {
            $user = [
                'login'      => self::ADMIN_LOGIN,
                'first_name' => 'Администратор',
                'status'     => true,
                'password'   => Hash::make($adminPassword),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // users на Render не обязательно совпадает со стоковой миграцией
            // Laravel (создавалась не в этом деплое) — заполняем name/email,
            // только если такие колонки реально есть, а не гадаем.
            if (Schema::hasColumn('users', 'name')) {
                $user['name'] = 'Администратор';
            }
            if (Schema::hasColumn('users', 'email')) {
                $user['email'] = 'admin@garage.local';
            }

            DB::table('users')->insert($user);
        } elseif (!$adminPassword) {
            Log::warning('Seed skipped: INITIAL_ADMIN_PASSWORD is not set, no admin user created.');
        }

        if ($clientSecret && !DB::table('oauth_clients')->where('id', 1)->exists()) {
            DB::table('oauth_clients')->insert([
                'id'                     => 1,
                'user_id'                => null,
                'name'                   => 'Garage Admin Password Grant',
                'secret'                 => $clientSecret,
                'provider'               => 'users',
                'redirect'               => 'http://localhost',
                'personal_access_client' => false,
                'password_client'        => true,
                'revoked'                => false,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        } elseif (!$clientSecret) {
            Log::warning('Seed skipped: PASSPORT_GRANT_CLIENT_SECRET is not set, no oauth password-grant client created.');
        }
    }

    public function down(): void
    {
        DB::table('users')->where('login', self::ADMIN_LOGIN)->delete();
        DB::table('oauth_clients')->where('id', 1)->delete();
    }
};
