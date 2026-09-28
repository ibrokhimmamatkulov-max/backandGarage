<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequest;
use App\Http\Requests\UpdateRequest;
use App\Http\Resources\CarConditionResource;
use App\Models\CarCondition;
use Illuminate\Http\Request;

class CarConditionController extends Controller
{
    public function index(Request $request)
    {
        $limit = 100;
        if ($request->has('limit') && (int)$request->limit > 0) {
            $limit = $this->clampPerPage((int) $request->limit);
        }

        $query = CarCondition::query();

        if ($request->has('filter_id')) {
            $query->FilterInt('car_conditions.id', $request->filter_id_condition ?? null, $request->filter_id);
        }
        if ($request->has('filter_name')) {
            $query->FilterString('car_conditions.name', $request->filter_name_condition ?? null, $request->filter_name);
        }
        if ($request->has('filter_level')) {
            $query->FilterInt('car_conditions.level', $request->filter_level_condition ?? null, $request->filter_level);
        }

        return $this->success(CarConditionResource::collection($query->orderByDesc('id')->limit($limit)->get()));
    }

    public function store(StoreRequest $request)
    {
        $car = CarCondition::create($request->validated());
        return $this->success(new CarConditionResource($car), 'Состояние авто создано', 201);
    }

    public function edit(int $id)
    {
        $car = CarCondition::find($id);
        if (!$car) {
            return $this->error('Состояние авто не найдено', 404);
        }
        return $this->success(new CarConditionResource($car));
    }

    public function update(UpdateRequest $request, CarCondition $carCondition)
    {
        $carCondition->update($request->validated());
        return $this->success(new CarConditionResource($carCondition));
    }
}
