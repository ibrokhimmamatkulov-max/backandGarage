<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientFavorite;
use App\Models\PerformerTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Избранное клиента: список id объявлений. Карточки фронт подтягивает
 * обычной публичной ручкой /landing/offers/{id}, поэтому здесь дублировать
 * ресурс объявления незачем.
 */
class FavoriteController extends Controller
{
    /** Потолок против мусора: живому человеку сотни избранных не нужны */
    private const MAX_FAVORITES = 200;

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);

        return $this->success(['ids' => $this->ids($client)]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);

        $exists = PerformerTransport::query()->visibleOnShowcase()->whereKey($id)->exists();

        if (!$exists) {
            return $this->error('Объявление не найдено или больше не доступно.', 404);
        }

        $already = ClientFavorite::where('client_id', $client->id)
            ->where('performer_transport_id', $id)
            ->exists();

        if (!$already) {
            if ($client->favorites()->count() >= self::MAX_FAVORITES) {
                return $this->error('В избранном не может быть больше ' . self::MAX_FAVORITES . ' объявлений.', 422);
            }

            ClientFavorite::create([
                'client_id'              => $client->id,
                'performer_transport_id' => $id,
            ]);
        }

        return $this->success(['ids' => $this->ids($client)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);

        ClientFavorite::where('client_id', $client->id)
            ->where('performer_transport_id', $id)
            ->delete();

        return $this->success(['ids' => $this->ids($client)]);
    }

    private function client(Request $request): Client
    {
        $client = $request->user('client');

        // См. AuthController::me — чужой токен под guard 'client' не принимаем
        abort_unless($client instanceof Client, 401, 'Unauthorized');

        return $client;
    }

    /** @return array<int, string> Новые сверху; строки — как id хранятся на фронте */
    private function ids(Client $client): array
    {
        return ClientFavorite::where('client_id', $client->id)
            ->orderByDesc('id')
            ->pluck('performer_transport_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
