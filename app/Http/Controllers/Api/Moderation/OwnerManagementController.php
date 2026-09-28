<?php

namespace App\Http\Controllers\Api\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Resources\Owner\OwnerResource;
use App\Models\Owner;
use App\Models\PerformerTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OwnerManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owners = Owner::query()
            ->withCount(['listings', 'applications'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($sub) use ($search) {
                    $sub->where('phone', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('login', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($this->clampPerPage($request->integer('per_page', 20)));

        return $this->success([
            'data' => collect($owners->items())->map(fn ($owner) => array_merge(
                (new OwnerResource($owner))->toArray($request),
                [
                    'listings_count'     => $owner->listings_count,
                    'applications_count' => $owner->applications_count,
                ]
            )),
            'meta' => [
                'total'        => $owners->total(),
                'per_page'     => $owners->perPage(),
                'current_page' => $owners->currentPage(),
                'last_page'    => $owners->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $owner = Owner::with(['listings.model_car.brand', 'documents'])->find($id);

        if (!$owner) {
            return $this->error('Арендодатель не найден.', 404);
        }

        return $this->success([
            'owner'    => new OwnerResource($owner),
            'listings' => $owner->listings->map(fn ($l) => [
                'id'                => $l->id,
                'title'             => $l->title,
                'brand'             => $l->model_car?->brand?->name,
                'model'             => $l->model_car?->car_model,
                'car_number'        => $l->car_number,
                'moderation_status' => $l->moderation_status,
                'created_at'        => $l->created_at?->toIso8601String(),
            ]),
            'documents' => $owner->documents->map(fn ($d) => [
                'id'            => $d->id,
                'type'          => $d->type,
                'original_name' => $d->original_name,
                'status'        => $d->status,
            ]),
        ]);
    }

    public function block(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $owner = Owner::find($id);

        if (!$owner) {
            return $this->error('Арендодатель не найден.', 404);
        }

        $owner->update([
            'status'         => Owner::STATUS_BLOCKED,
            'blocked_reason' => $request->input('reason'),
        ]);

        // Токены гасим сразу — иначе заблокированный продолжит работать
        // до истечения срока действия.
        $owner->tokens()->delete();

        // Объявления не удаляем: снимаются с витрины через scopeVisibleOnShowcase,
        // при разблокировке возвращаются сами.
        $affected = PerformerTransport::where('owner_id', $owner->id)
            ->where('moderation_status', PerformerTransport::STATUS_PUBLISHED)
            ->count();

        return $this->success(
            ['listings_hidden' => $affected],
            "Арендодатель заблокирован. С витрины снято объявлений: {$affected}."
        );
    }

    public function unblock(int $id): JsonResponse
    {
        $owner = Owner::find($id);

        if (!$owner) {
            return $this->error('Арендодатель не найден.', 404);
        }

        $owner->update([
            'status'         => Owner::STATUS_ACTIVE,
            'blocked_reason' => null,
        ]);

        return $this->success(new OwnerResource($owner->fresh()), 'Арендодатель разблокирован.');
    }
}
