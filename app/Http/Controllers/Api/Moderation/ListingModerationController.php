<?php

namespace App\Http\Controllers\Api\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Resources\Listing\OwnerListingResource;
use App\Http\Resources\Owner\OwnerResource;
use App\Models\PerformerTransport;
use App\Services\Listing\ListingService;
use App\Services\Listing\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ListingModerationController extends Controller
{
    public function __construct(
        private readonly ModerationService $moderation,
        private readonly ListingService $listings,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // 'status' раньше был обязателен по факту — умолчание 'pending' не давало
        // посмотреть объявления в любом другом статусе разом. Ручкой никто ещё не
        // пользовался (админка вместо неё по ошибке ходила в публичную /landing/offers,
        // которая отдаёт только опубликованное), так что менять умолчание можно
        // без риска сломать существующий вызов.
        $status = $request->input('status');

        $listings = PerformerTransport::query()
            ->whereNotNull('owner_id')
            ->when($status, fn ($q) => $q->where('moderation_status', $status))
            ->with(['model_car.brand', 'city', 'owner', 'photos', 'priceTiers', 'taxiTariff'])
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->integer('city_id')))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('owner_id', $request->integer('owner_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($sub) use ($search) {
                    $sub->where('car_number', 'like', "%{$search}%")
                        ->orWhereHas('model_car', fn ($m) => $m->where('car_model', 'like', "%{$search}%"))
                        ->orWhereHas('model_car.brand', fn ($m) => $m->where('name', 'like', "%{$search}%"));
                });
            })
            // Живая очередь на проверке — по возрасту заявки, старые первыми
            // (честная очередь, а не стек). Во всех остальных режимах —
            // недавно поданные сверху, это ближе к тому, что хотят увидеть
            // при обычном просмотре объявлений.
            ->orderBy('submitted_at', $status === PerformerTransport::STATUS_PENDING ? 'asc' : 'desc')
            ->paginate($this->clampPerPage($request->integer('per_page', 20)));

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

    public function show(int $id): JsonResponse
    {
        $listing = PerformerTransport::with([
            'model_car.brand', 'city', 'gearbox', 'body_type', 'color', 'fuel_type',
            'photos', 'terms', 'priceTiers', 'taxiTariff', 'unavailablePeriods', 'owner', 'documents',
        ])->find($id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $otherListings = $listing->owner_id
            ? PerformerTransport::where('owner_id', $listing->owner_id)
                ->where('id', '!=', $listing->id)
                ->get(['id', 'title', 'car_number', 'moderation_status'])
            : collect();

        return $this->success([
            'listing'         => new OwnerListingResource($listing),
            'owner'           => $listing->owner ? new OwnerResource($listing->owner) : null,
            'owner_listings'  => $otherListings,
            'publish_blockers' => $this->listings->publishBlockers($listing),
            'documents'       => $listing->documents->map(fn ($d) => [
                'id'            => $d->id,
                'type'          => $d->type,
                'original_name' => $d->original_name,
                'status'        => $d->status,
            ]),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $listing = PerformerTransport::find($id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $this->moderation->approve($listing, auth()->id(), $request->input('comment'));

        return $this->success(new OwnerListingResource($listing), 'Объявление опубликовано.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason'  => 'required|string|max:500',
            'comment' => 'nullable|string|max:2000',
        ], [
            'reason.required' => 'Укажите причину отклонения — владелец её увидит.',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $listing = PerformerTransport::find($id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $this->moderation->reject(
            $listing,
            auth()->id(),
            $request->input('reason'),
            $request->input('comment')
        );

        return $this->success(new OwnerListingResource($listing), 'Объявление отклонено.');
    }

    /**
     * Платный подъём в топ выдачи. boosted_until — единственное поле,
     * которым это управляется: сортировка на витрине уже учитывает его
     * (см. LandingController::applySorting).
     */
    public function boost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'required|integer|min:1|max:365',
        ], [
            'days.required' => 'Укажите, на сколько дней поднять объявление.',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $listing = PerformerTransport::find($id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $days = $request->integer('days');
        $listing->boosted_until = now()->addDays($days);
        $listing->save();

        return $this->success(
            new OwnerListingResource($listing),
            "Объявление поднято в топ на {$days} дн."
        );
    }

    public function unboost(int $id): JsonResponse
    {
        $listing = PerformerTransport::find($id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $listing->boosted_until = null;
        $listing->save();

        return $this->success(new OwnerListingResource($listing), 'Объявление снято с топа.');
    }

    public function logs(int $id): JsonResponse
    {
        $listing = PerformerTransport::find($id);

        if (!$listing) {
            return $this->error('Объявление не найдено.', 404);
        }

        $logs = $listing->moderationLogs()->with('moderator:id,first_name,last_name,login')->get()
            ->map(fn ($log) => [
                'id'          => $log->id,
                'from_status' => $log->from_status,
                'to_status'   => $log->to_status,
                'comment'     => $log->comment,
                'moderator'   => $log->moderator ? [
                    'id'    => $log->moderator->id,
                    'name'  => trim($log->moderator->first_name . ' ' . $log->moderator->last_name),
                    'login' => $log->moderator->login,
                ] : null,
                'created_at'  => $log->created_at?->toIso8601String(),
            ]);

        return $this->success($logs);
    }
}
