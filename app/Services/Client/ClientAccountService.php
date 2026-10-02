<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Services\Owner\PhoneNormalizer;

/**
 * Аккаунт клиента создаётся сам, при первом подтверждении номера кодом
 * (в окне входа или при отправке заявки). Ни пароля, ни отдельной
 * регистрации нет: подтверждённый номер и есть аккаунт.
 */
class ClientAccountService
{
    public function findByPhone(string $phone): ?Client
    {
        return Client::where('phone', PhoneNormalizer::normalize($phone))->first();
    }

    public function findOrCreateByPhone(string $phone, ?string $name = null): Client
    {
        $normalized = PhoneNormalizer::normalize($phone);

        $client = Client::firstOrCreate(
            ['phone' => $normalized],
            ['name' => $name ?: null, 'phone_verified_at' => now()],
        );

        // Имя дописываем, только если его ещё не было: повторная заявка
        // с другим написанием не должна затирать то, что человек указал раньше.
        if (!$client->name && $name) {
            $client->name = $name;
        }

        $client->last_login_at = now();
        $client->save();

        return $client;
    }

    public function issueToken(Client $client): string
    {
        // Срок жизни — config/sanctum.php (expiration). Просроченные токены
        // подчищаем, чтобы они не копились; активные на других устройствах
        // не трогаем — вход с телефона не должен выкидывать с ноутбука.
        $ttlDays = (int) config('otp.token_ttl_days', 30);
        $client->tokens()->where('created_at', '<', now()->subDays($ttlDays))->delete();

        return $client->createToken('client-site')->plainTextToken;
    }

    /** @return array{id: int, phone: string, name: ?string} */
    public function present(Client $client): array
    {
        return [
            'id'    => $client->id,
            'phone' => $client->phone,
            'name'  => $client->name,
        ];
    }
}
