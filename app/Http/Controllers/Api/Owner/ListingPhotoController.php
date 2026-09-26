<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\PerformerTransportPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ListingPhotoController extends Controller
{
    private const MAX_PHOTOS = 15;

    public function index(Request $request, int $id): JsonResponse
    {
        $photos = PerformerTransportPhoto::where('performer_transport_id', $id)
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'url' => Storage::url($p->path)]);

        return $this->success($photos);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'photos'   => 'required|array|min:1',
            // 'image' вместо 'file' здесь ломался на реальных телефонных
            // снимках: правило гоняет файл через getimagesize() и падает на
            // всём, что не bare JPEG/PNG (HEIC под .jpg-расширением и т.п.),
            // хотя расширение и mimes проходят нормально. У документов
            // (ListingDocumentController) то же самое уже сделано через
            // file|mimes — там это не ломалось.
            'photos.*' => 'file|mimes:jpeg,jpg,png,webp|max:5120',
        ], [
            'photos.*.max'   => 'Каждое фото — не больше 5 МБ.',
            'photos.*.mimes' => 'Допустимые форматы: JPG, PNG, WebP.',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error', 422, $validator->errors());
        }

        $existing = PerformerTransportPhoto::where('performer_transport_id', $id)->count();
        $incoming = count($request->file('photos'));

        if ($existing + $incoming > self::MAX_PHOTOS) {
            return $this->error(
                'Больше ' . self::MAX_PHOTOS . ' фотографий загрузить нельзя. '
                . "Сейчас загружено {$existing}.",
                422
            );
        }

        $created = [];

        foreach ($request->file('photos') as $file) {
            $path = $file->store('cars', 'public');

            $photo = PerformerTransportPhoto::create([
                'performer_transport_id' => $id,
                'path'                   => $path,
            ]);

            $created[] = ['id' => $photo->id, 'url' => Storage::url($path)];
        }

        return $this->success($created, 'Фотографии загружены.', 201);
    }

    public function destroy(Request $request, int $id, int $photoId): JsonResponse
    {
        $photo = PerformerTransportPhoto::where('performer_transport_id', $id)
            ->where('id', $photoId)
            ->first();

        if (!$photo) {
            return $this->error('Фотография не найдена.', 404);
        }

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return $this->success(null, 'Фотография удалена.');
    }
}
