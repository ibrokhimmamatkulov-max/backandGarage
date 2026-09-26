<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ListingRequest;
use App\Http\Resources\Listing\OwnerListingResource;
use App\Models\PerformerTransport;
use App\Services\Listing\ListingService;
use App\Services\Listing\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function __construct(
        private readonly ListingService $listings,
        private readonly ModerationService $moderation,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user('owner');

        $listings = PerformerTransport::query()
            ->ownedBy($owner->id)
            ->with(['model_car.brand', 'city', 'gearbox', 'body_type', 'photos', 'priceTiers', 'taxiTariff'])
            ->withCount('applications')
            // «Все» значит все — включая архив, у него теперь есть выход
            // (публикация обратно), так что прятать эти карточки незачем.
            ->when($request->filled('status'), fn ($q) => $q->where('moderation_status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'data' => OwnerListingResource::collection($listings),
            'meta' => [
                'total'        => $listings->total(),
                'per_page'     => $listings->perPage(),
                'current_page' => $listings->currentPage(),
                'last_page'    => $listings->lastPage(),
            ],
        ]);
    }

    public function store(ListingRequest $request): JsonResponse
    {
        $owner = $request->user('owner');

        $listing = $this->listings->create($owner, $request->validated());

        return $this->success(
            new OwnerListingResource($listing->load(['model_car.brand', 'city', 'terms', 'priceTiers', 'dopOptions.car_option'])),
            'Объявление отправлено на проверку.',
            201
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        return $this->success(new OwnerListingResource($listing->load([
            'model_car.brand', 'city', 'gearbox', 'body_type', 'color', 'fuel_type',
            'photos', 'terms', 'priceTiers', 'unavailablePeriods', 'dopOptions.car_option',
        ])));
    }

    public function update(ListingRequest $request, int $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        if ($listing->moderation_status === PerformerTransport::STATUS_ARCHIVED) {
            return $this->error('Архивное объявление нельзя редактировать.', 422);
        }

        $result = $this->listings->update($listing, $request->validated());

        return $this->success(
            new OwnerListingResource($result['listing']->load(['model_car.brand', 'city', 'terms', 'priceTiers', 'dopOptions.car_option'])),
            $result['returned_to_moderation']
                ? 'Изменения сохранены. Объявление отправлено на повторную проверку, до её окончания на сайте показывается прежняя версия.'
                : 'Изменения сохранены.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $this->moderation->archive($listing);

        return $this->success(null, 'Объявление удалено.');
    }

    private const PAUSE_REASONS = [
        'rented_out'   => 'Сдал в аренду',
        'changed_mind' => 'Передумал сдавать',
        'other'        => 'Другая причина',
    ];

    public function pause(Request $request, int $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $validator = validator($request->all(), [
            'reason'  => 'nullable|in:' . implode(',', array_keys(self::PAUSE_REASONS)),
            'comment' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $reason = $request->input('reason');
        $label = $reason ? self::PAUSE_REASONS[$reason] : null;
        $freeText = trim((string) $request->input('comment'));

        $comment = match (true) {
            $label && $freeText => "{$label}: {$freeText}",
            (bool) $label       => $label,
            (bool) $freeText    => $freeText,
            default             => null,
        };

        $this->moderation->pause($listing, $comment);

        return $this->success(new OwnerListingResource($listing), 'Объявление снято с публикации.');
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $this->moderation->resume($listing);

        return $this->success(new OwnerListingResource($listing), 'Объявление снова на сайте.');
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $this->moderation->resubmit($listing);

        return $this->success(new OwnerListingResource($listing), 'Объявление отправлено на повторную проверку.');
    }

    /**
     * Второй рубеж проверки владения — после middleware owns.listing.
     */
    private function findOwned(Request $request, int $id): ?PerformerTransport
    {
        return PerformerTransport::query()
            ->ownedBy($request->user('owner')->id)
            ->find($id);
    }
}
