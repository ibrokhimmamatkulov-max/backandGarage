<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CarCategoryRequest;
use App\Http\Resources\CarCategoryResource;
use App\Models\CategoryCar;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CarCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = CategoryCar::query();
        $limit = 100;

        if ($request->has('limit')) $limit = $this->clampPerPage((int) $request->limit);

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
        if ($request->has('filter_description')) {
            $desc = $request->filter_description;
            match ($request->filter_description_condition) {
                'startLike' => $query->where('description', 'LIKE', "$desc%"),
                'endLike'   => $query->where('description', 'LIKE', "%$desc"),
                'include'   => $query->where('description', 'LIKE', "%$desc%"),
                default     => $query->where('description', $desc),
            };
        }
        if ($request->has('filter_from_created_at')) {
            $query->where('created_at', '>=', Carbon::parse($request->filter_from_created_at)->format('Y-m-d H:i'));
        }
        if ($request->has('filter_to_created_at')) {
            $query->where('created_at', '<=', Carbon::parse($request->filter_to_created_at)->format('Y-m-d H:i'));
        }

        return $this->success(CarCategoryResource::collection($query->orderByDesc('id')->limit($limit)->get()));
    }

    public function store(CarCategoryRequest $request)
    {
        CategoryCar::updateOrCreate(
            ['name' => $request->name],
            [
                'description' => $request->description ?? null,
                'is_active'   => $request->is_active ?? 1,
            ]
        );
        return $this->success(null, 'Категория авто добавлена', 201);
    }

    public function show(int $id)
    {
        $category = CategoryCar::find($id);
        if (!$category) {
            return $this->error('Категория автомобиля не найдена', 404);
        }
        return $this->success(new CarCategoryResource($category));
    }

    public function update(CarCategoryRequest $request, int $id)
    {
        $category = CategoryCar::find($id);
        if (!$category) {
            return $this->error('Категория автомобиля не найдена', 404);
        }
        $category->update([
            'name'        => $request->name,
            'description' => $request->description ?? null,
            'is_active'   => $request->is_active ?? 1,
        ]);
        return $this->success(null, 'Категория авто изменена');
    }
}
