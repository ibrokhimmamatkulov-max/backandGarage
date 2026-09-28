<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CarModelRequest;
use App\Http\Resources\CarModelResource;
use App\Models\CarBrand;
use App\Models\CategoryCar;
use App\Models\Marka;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CarModelController extends Controller
{
    public function data(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_car_id' => ['required', Rule::exists(CategoryCar::class, 'id')],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $category  = CategoryCar::find($request->category_car_id);
        $car_classes = $category
            ? $category->class_cars()->where('is_active', 1)->get(['category_car_id', 'id', 'name'])
            : [];

        return $this->success([
            'car_classes' => $car_classes,
            'car_brands'  => CarBrand::where('is_active', 1)->get(['id', 'name']),
        ]);
    }

    public function index(Request $request)
    {
        $carModels = Marka::query()->with(['category_car', 'brand', 'class_car']);
        $limit = 100;

        if ($request->has('limit'))                  $limit = $this->clampPerPage((int) $request->limit);
        if ($request->has('filter_is_active'))       $carModels->where('is_active', $request->filter_is_active);
        if ($request->has('filter_category_car_id')) $carModels->where('category_car_id', $request->filter_category_car_id);
        if ($request->has('filter_car_brand_id'))    $carModels->where('car_brand_id', $request->filter_car_brand_id);
        if ($request->has('filter_class_car_id'))    $carModels->where('class_car_id', $request->filter_class_car_id);
        if ($request->has('filter_car_seat_from'))   $carModels->where('car_seat_from', '>=', $request->filter_car_seat_from);
        if ($request->has('filter_car_seat_before')) $carModels->where('car_seat_before', '>=', $request->filter_car_seat_before);

        if ($request->has('filter_id')) {
            $filter_id = $request->filter_id;
            match ($request->filter_id_condition) {
                'equalOrMore' => $carModels->where('id', '>=', $filter_id),
                'equalOrLess' => $carModels->where('id', '<=', $filter_id),
                'less'        => $carModels->where('id', '<', $filter_id),
                'more'        => $carModels->where('id', '>', $filter_id),
                default       => $carModels->where('id', $filter_id),
            };
        }
        if ($request->has('filter_name')) {
            $name = $request->filter_name;
            match ($request->filter_name_condition) {
                'startLike' => $carModels->where('name', 'LIKE', "$name%"),
                'endLike'   => $carModels->where('name', 'LIKE', "%$name"),
                'include'   => $carModels->where('name', 'LIKE', "%$name%"),
                default     => $carModels->where('name', $name),
            };
        }
        if ($request->has('filter_from_created_at')) {
            $carModels->where('created_at', '>=', Carbon::parse($request->filter_from_created_at)->format('Y-m-d H:i'));
        }
        if ($request->has('filter_to_created_at')) {
            $carModels->where('created_at', '<=', Carbon::parse($request->filter_to_created_at)->format('Y-m-d H:i'));
        }

        return $this->success(CarModelResource::collection($carModels->orderByDesc('id')->limit($limit)->get()));
    }

    public function store(CarModelRequest $request)
    {
        $exists = Marka::where('category_car_id', $request->category_car_id)
            ->where('car_brand_id', $request->car_brand_id)
            ->where('car_model', $request->car_model)
            ->exists();

        if ($exists) {
            return $this->error('Такой тип модели уже существует!', 422);
        }

        Marka::create([
            'name'           => $request->name,
            'car_model'      => $request->car_model,
            'car_seat_from'  => $request->car_seat_from ?? null,
            'car_seat_before'=> $request->car_seat_before ?? null,
            'car_brand_id'   => $request->car_brand_id,
            'category_car_id'=> $request->category_car_id,
            'class_car_id'   => $request->class_car_id,
            'is_active'      => $request->is_active ?? 1,
        ]);

        return $this->success(null, 'Модель авто добавлена', 201);
    }

    public function edit(int $id)
    {
        $car_model = Marka::find($id);
        if (!$car_model) {
            return $this->error('Модель авто не найдена', 404);
        }
        return $this->success(new CarModelResource($car_model));
    }

    public function update(CarModelRequest $request, int $id)
    {
        $car_model = Marka::find($id);
        if (!$car_model) {
            return $this->error('Модель авто не найдена', 404);
        }

        $car_model->update([
            'name'           => $request->name,
            'car_model'      => $request->car_model,
            'car_seat_from'  => $request->car_seat_from ?? null,
            'car_seat_before'=> $request->car_seat_before ?? null,
            'car_brand_id'   => $request->car_brand_id,
            'category_car_id'=> $request->category_car_id,
            'class_car_id'   => $request->class_car_id,
            'is_active'      => $request->is_active ?? 1,
        ]);

        return $this->success(null, 'Марка авто изменена');
    }
}
