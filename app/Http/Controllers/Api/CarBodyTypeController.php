<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BodyTypeRequest;
use App\Http\Resources\BodyTypeResource;
use App\Models\BodyType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarBodyTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = BodyType::query();
        $limit = 100;

        if ($request->has('limit')) $limit = $this->clampPerPage((int) $request->limit);
        if ($request->has('filter_is_active')) $query->where('is_active', $request->filter_is_active);
        if ($request->has('filter_category_car_id')) $query->where('category_car_id', $request->filter_category_car_id);

        if ($request->has('filter_id')) {
            $filter_id = $request->filter_id;
            match ($request->filter_id_condition) {
                'equalOrMore' => $query->where('id', '>=', $filter_id),
                'equalOrLess' => $query->where('id', '<=', $filter_id),
                'less'        => $query->where('id', '<', $filter_id),
                'more'        => $query->where('id', '>', $filter_id),
                default       => $query->where('id', $filter_id),
            };
        }
        if ($request->has('filter_name')) {
            $name = $request->filter_name;
            match ($request->filter_name_condition) {
                'startLike' => $query->where('name', 'LIKE', "$name%"),
                'endLike'   => $query->where('name', 'LIKE', "%$name"),
                'include'   => $query->where('name', 'LIKE', "%$name%"),
                default     => $query->where('name', $name),
            };
        }
        if ($request->has('filter_from_created_at')) {
            $query->where('created_at', '>=', Carbon::parse($request->filter_from_created_at)->format('Y-m-d H:i'));
        }
        if ($request->has('filter_to_created_at')) {
            $query->where('created_at', '<=', Carbon::parse($request->filter_to_created_at)->format('Y-m-d H:i'));
        }

        return $this->success(BodyTypeResource::collection($query->limit($limit)->get()));
    }

    public function store(BodyTypeRequest $request)
    {
        DB::beginTransaction();
        try {
            $body_type = BodyType::create($request->validated());
            DB::commit();
            return $this->success(new BodyTypeResource($body_type), 'Тип кузова создан', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Произошла системная ошибка: ' . $e->getMessage(), 500);
        }
    }

    public function edit(int $id)
    {
        $body_type = BodyType::find($id);
        if (!$body_type) {
            return $this->error('Тип кузова не найден', 404);
        }
        return $this->success(new BodyTypeResource($body_type));
    }

    public function update(BodyTypeRequest $request, int $id)
    {
        $body_type = BodyType::find($id);
        if (!$body_type) {
            return $this->error('Тип кузова не найден', 404);
        }
        $body_type->update($request->validated());
        return $this->success(null, 'Тип кузова изменён');
    }
}
