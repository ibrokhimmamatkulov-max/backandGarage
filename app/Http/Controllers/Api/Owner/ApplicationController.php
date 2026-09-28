<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\ApplicationStatus;
use App\Models\RentalApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Заявки по объявлениям владельца.
 *
 * Телефон арендатора отдаётся целиком и намеренно: это и есть то, за чем
 * владелец сюда приходит (ТЗ §6.3).
 */
class ApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owner = $request->user('owner');

        $applications = RentalApplication::query()
            ->ownedBy($owner->id)
            ->with(['car.model_car.brand', 'car.photos', 'status', 'city', 'priceTier'])
            ->when($request->filled('status_id'), fn ($q) => $q->where('status_id', $request->integer('status_id')))
            ->when($request->filled('listing_id'), fn ($q) => $q->where('performer_transport_id', $request->integer('listing_id')))
            ->orderByDesc('id')
            ->paginate($this->clampPerPage($request->integer('per_page', 20)));

        return $this->success([
            'data' => collect($applications->items())->map(fn ($a) => $this->present($a)),
            'meta' => [
                'total'        => $applications->total(),
                'per_page'     => $applications->perPage(),
                'current_page' => $applications->currentPage(),
                'last_page'    => $applications->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $application = $this->findOwned($request, $id);

        if (!$application) {
            return $this->error('Заявка не найдена.', 404);
        }

        return $this->success($this->present(
            $application->load(['car.model_car.brand', 'car.photos', 'status', 'city', 'priceTier'])
        ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $application = $this->findOwned($request, $id);

        if (!$application) {
            return $this->error('Заявка не найдена.', 404);
        }

        $validator = Validator::make($request->all(), [
            'status_id' => 'required|integer|exists:application_statuses,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $application->update([
            'status_id'         => $request->integer('status_id'),
            'status_changed_by' => RentalApplication::CHANGED_BY_OWNER,
            'status_changed_at' => now(),
        ]);

        return $this->success($this->present($application->fresh(['status'])), 'Статус обновлён.');
    }

    public function statuses(): JsonResponse
    {
        return $this->success(ApplicationStatus::orderBy('id')->get(['id', 'code', 'name']));
    }

    private function findOwned(Request $request, int $id): ?RentalApplication
    {
        return RentalApplication::query()
            ->ownedBy($request->user('owner')->id)
            ->find($id);
    }

    private function present(RentalApplication $application): array
    {
        return [
            'id'      => $application->id,
            'name'    => $application->name,
            'phone'   => $application->phone,
            'comment' => $application->comment,

            'desired_start_date' => $application->desired_start_date?->toDateString(),
            'desired_end_date'   => $application->desired_end_date?->toDateString(),
            'calculated_total'   => $application->calculated_total !== null
                ? (float) $application->calculated_total
                : null,

            'status' => $application->status ? [
                'id'   => $application->status->id,
                'code' => $application->status->code,
                'name' => $application->status->name,
            ] : null,
            'status_changed_by' => $application->status_changed_by,
            'status_changed_at' => $application->status_changed_at?->toIso8601String(),

            'city' => $application->city ? [
                'id'   => $application->city->id,
                'name' => $application->city->name,
            ] : null,

            'listing' => $application->car ? [
                'id'    => $application->car->id,
                'brand' => $application->car->model_car?->brand?->name,
                'model' => $application->car->model_car?->car_model,
                'year'  => $application->car->year_of_issue,
            ] : null,

            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }
}
