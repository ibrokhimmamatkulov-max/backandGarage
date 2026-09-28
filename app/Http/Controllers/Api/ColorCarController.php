<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ColorCarRequest;
use App\Http\Resources\CarColorResource;
use App\Models\ColorCar;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ColorCarController extends Controller
{
    public function index(Request $request)
    {
        $query = ColorCar::query()->with(['createdBy']);
        $limit = 100;

        if ($request->has('limit')) $limit = $this->clampPerPage((int) $request->limit);
        if ($request->has('filter_is_active')) $query->where('is_active', $request->filter_is_active);

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
        if ($request->has('filter_name_tj')) {
            $name_tj = $request->filter_name_tj;
            match ($request->filter_name_tj_condition) {
                'startLike' => $query->where('name_tj', 'LIKE', "$name_tj%"),
                'endLike'   => $query->where('name_tj', 'LIKE', "%$name_tj"),
                'include'   => $query->where('name_tj', 'LIKE', "%$name_tj%"),
                default     => $query->where('name_tj', $name_tj),
            };
        }
        if ($request->has('filter_name_for_sms')) {
            $sms = $request->filter_name_for_sms;
            match ($request->filter_name_for_sms_condition) {
                'startLike' => $query->where('name_for_sms', 'LIKE', "$sms%"),
                'endLike'   => $query->where('name_for_sms', 'LIKE', "%$sms"),
                'include'   => $query->where('name_for_sms', 'LIKE', "%$sms%"),
                default     => $query->where('name_for_sms', $sms),
            };
        }
        if ($request->has('filter_from_created_at')) {
            $query->where('created_at', '>=', Carbon::parse($request->filter_from_created_at)->format('Y-m-d H:i'));
        }
        if ($request->has('filter_to_created_at')) {
            $query->where('created_at', '<=', Carbon::parse($request->filter_to_created_at)->format('Y-m-d H:i'));
        }

        return $this->success(CarColorResource::collection($query->limit($limit)->get()));
    }

    public function store(ColorCarRequest $request)
    {
        $validated = $request->validated();
        $color = new ColorCar();
        $color->name         = $validated['name'];
        $color->name_tj      = $validated['name_tj'];
        $color->name_for_sms = $validated['name_for_sms'];
        $color->is_active    = $validated['is_active'] ?? 1;
        $color->created_by   = auth()->id();
        $color->save();
        return $this->success(null, 'Цвет добавлен', 201);
    }

    public function edit(int $id)
    {
        $color = ColorCar::find($id);
        if (!$color) {
            return $this->error('Цвет не найден', 404);
        }
        return $this->success(new CarColorResource($color));
    }

    public function update(ColorCarRequest $request, int $id)
    {
        $color = ColorCar::find($id);
        if (!$color) {
            return $this->error('Цвет не найден', 404);
        }
        $validated = $request->validated();
        $color->name         = $validated['name'];
        $color->name_tj      = $validated['name_tj'];
        $color->name_for_sms = $validated['name_for_sms'];
        $color->is_active    = $validated['is_active'] ?? 1;
        $color->update();
        return $this->success(null, 'Цвет изменён');
    }
}
