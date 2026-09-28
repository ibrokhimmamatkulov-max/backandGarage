<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CarBrandRequest;
use App\Http\Resources\CarBrandResource;
use App\Models\CarBrand;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CarBrandController extends Controller
{
    public function index(Request $request)
    {
        $car_brand = CarBrand::query();
        $limit = 100;

        if ($request->has('limit')) {
            $limit = $this->clampPerPage((int) $request->limit);
        }
        if ($request->has('filter_is_active')) {
            $car_brand->where('is_active', $request->filter_is_active);
        }
        if ($request->has('filter_id')) {
            $filter_id = $request->filter_id;
            match ($request->filter_id_condition) {
                'equalOrMore' => $car_brand->where('id', '>=', $filter_id),
                'equalOrLess' => $car_brand->where('id', '<=', $filter_id),
                'less'        => $car_brand->where('id', '<', $filter_id),
                'more'        => $car_brand->where('id', '>', $filter_id),
                default       => $car_brand->where('id', $filter_id),
            };
        }
        if ($request->has('filter_name')) {
            $name = $request->filter_name;
            match ($request->filter_name_condition) {
                'startLike' => $car_brand->where('name', 'LIKE', "$name%"),
                'endLike'   => $car_brand->where('name', 'LIKE', "%$name"),
                'include'   => $car_brand->where('name', 'LIKE', "%$name%"),
                default     => $car_brand->where('name', $name),
            };
        }
        if ($request->has('filter_description')) {
            $description = $request->filter_description;
            match ($request->filter_description_condition) {
                'startLike' => $car_brand->where('description', 'LIKE', "$description%"),
                'endLike'   => $car_brand->where('description', 'LIKE', "%$description"),
                'include'   => $car_brand->where('description', 'LIKE', "%$description%"),
                default     => $car_brand->where('description', $description),
            };
        }
        if ($request->has('filter_from_created_at')) {
            $car_brand->where('created_at', '>=', Carbon::parse($request->filter_from_created_at)->format('Y-m-d H:i'));
        }
        if ($request->has('filter_to_created_at')) {
            $car_brand->where('created_at', '<=', Carbon::parse($request->filter_to_created_at)->format('Y-m-d H:i'));
        }

        return $this->success(CarBrandResource::collection($car_brand->limit($limit)->get()));
    }

    public function store(CarBrandRequest $request)
    {
        CarBrand::updateOrCreate(
            ['name' => $request->name],
            [
                'description' => $request->description ?? null,
                'created_by'  => auth()->id(),
                'is_active'   => $request->is_active ?? 1,
            ]
        );
        return $this->success(null, 'Бренд авто добавлен', 201);
    }

    public function edit(int $id)
    {
        $car_brand = CarBrand::find($id);
        if (!$car_brand) {
            return $this->error('Бренд авто не найден', 404);
        }
        return $this->success(new CarBrandResource($car_brand));
    }

    public function update(CarBrandRequest $request, int $id)
    {
        $car_brand = CarBrand::find($id);
        if (!$car_brand) {
            return $this->error('Бренд авто не найден', 404);
        }
        $car_brand->update([
            'name'        => $request->name,
            'description' => $request->description ?? null,
            'is_active'   => $request->is_active ?? 1,
        ]);
        return $this->success(null, 'Бренд авто изменён');
    }
}
