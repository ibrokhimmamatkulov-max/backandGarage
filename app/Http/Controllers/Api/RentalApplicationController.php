<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationStatus;
use App\Models\RentalApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Заявки глазами менеджера — рабочий экран CRM.
 *
 * Раньше index() отдавал всё разом без фильтров и пагинации: на паре сотен
 * заявок это превращалось в неработающий список. Теперь фильтры, поиск,
 * пагинация и сводка по статусам для вкладок.
 */
class RentalApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RentalApplication::query()
            ->with(['car.model_car.brand', 'car.owner', 'tariff', 'city', 'status'])
            ->when($request->filled('status_id'), fn ($q) => $q->where('status_id', $request->integer('status_id')))
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->integer('city_id')))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('owner_id', $request->integer('owner_id')))
            ->when($request->filled('listing_id'), fn ($q) => $q->where('performer_transport_id', $request->integer('listing_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                // Менеджер ищет по тому, что у него перед глазами: телефон или имя
                $search = trim($request->input('search'));
                $digits = preg_replace('/\D+/', '', $search);

                $q->where(function ($sub) use ($search, $digits) {
                    $sub->where('name', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $sub->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            });

        $sort = $request->input('sort', 'new');
        match ($sort) {
            'old' => $query->orderBy('id'),
            default => $query->orderByDesc('id'),
        };

        $applications = $query->paginate($this->clampPerPage($request->integer('per_page', 30)));

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

    /** Счётчики для вкладок — считаются одним запросом, а не пятью */
    public function summary(): JsonResponse
    {
        $counts = RentalApplication::query()
            ->selectRaw('status_id, COUNT(*) as total')
            ->groupBy('status_id')
            ->pluck('total', 'status_id');

        $statuses = ApplicationStatus::orderBy('id')->get(['id', 'code', 'name']);

        return $this->success([
            'total'    => (int) $counts->sum(),
            'statuses' => $statuses->map(fn ($s) => [
                'id'    => $s->id,
                'code'  => $s->code,
                'name'  => $s->name,
                'count' => (int) ($counts[$s->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'performer_transport_id' => 'required|exists:performer_transports,id',
            'rental_tariff_id'       => 'nullable|exists:rental_tariffs,id',
            'user_id'                => 'nullable|exists:mysql_taxi.users,id',
            'name'                   => 'required|string|max:255',
            'phone'                  => 'required|string|max:30',
            'city_id'                => 'required|exists:cities,id',
            'promo_code'             => 'nullable|string|max:50',
            'status_id'              => 'required|exists:application_statuses,id',
            'desired_start_date'     => 'nullable|date_format:Y-m-d',
            'desired_end_date'       => 'nullable|date_format:Y-m-d|after:desired_start_date',
            'comment'                => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $application = RentalApplication::create($validator->validated() + ['source' => 'admin']);

        return $this->success($this->present($application->fresh(['status'])), 'Заявка создана', 201);
    }

    public function show(int $id): JsonResponse
    {
        $application = RentalApplication::with([
            'car.model_car.brand', 'car.owner', 'tariff', 'city', 'status',
        ])->find($id);

        if (!$application) {
            return $this->error('Заявка не найдена', 404);
        }

        return $this->success($this->present($application));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $application = RentalApplication::find($id);

        if (!$application) {
            return $this->error('Заявка не найдена', 404);
        }

        $validator = Validator::make($request->all(), [
            'status_id'  => 'sometimes|required|exists:application_statuses,id',
            'promo_code' => 'nullable|string|max:50',
            'comment'    => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $data = $validator->validated();

        if (isset($data['status_id'])) {
            $data['status_changed_by'] = RentalApplication::CHANGED_BY_MANAGER;
            $data['status_changed_at'] = now();
        }

        $application->update($data);

        return $this->success($this->present($application->fresh(['status', 'car.model_car.brand', 'city'])));
    }

    public function destroy(int $id): JsonResponse
    {
        $application = RentalApplication::find($id);

        if (!$application) {
            return $this->error('Заявка не найдена', 404);
        }

        $application->delete();

        return $this->success(null, 'Заявка удалена');
    }

    private function present(RentalApplication $a): array
    {
        return [
            'id'      => $a->id,
            'name'    => $a->name,
            'phone'   => $a->phone,
            'comment' => $a->comment,

            'desired_start_date' => $a->desired_start_date?->toDateString(),
            'desired_end_date'   => $a->desired_end_date?->toDateString(),
            'calculated_total'   => $a->calculated_total !== null ? (float) $a->calculated_total : null,

            'status' => $a->status ? [
                'id' => $a->status->id, 'code' => $a->status->code, 'name' => $a->status->name,
            ] : null,
            'status_changed_by' => $a->status_changed_by,
            'status_changed_at' => $a->status_changed_at?->toIso8601String(),

            'source'  => $a->source,
            'city'    => $a->city ? ['id' => $a->city->id, 'name' => $a->city->name] : null,

            'listing' => $a->car ? [
                'id'         => $a->car->id,
                'brand'      => $a->car->model_car?->brand?->name,
                'model'      => $a->car->model_car?->car_model,
                'year'       => $a->car->year_of_issue,
                'car_number' => $a->car->car_number,
            ] : null,

            // Кому уходит лид — менеджеру нужно понимать, с кем связываться
            'owner' => $a->car?->owner ? [
                'id'    => $a->car->owner->id,
                'name'  => $a->car->owner->display_name,
                'phone' => $a->car->owner->phone,
            ] : null,

            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
