<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function success($data = null, string $message = 'OK', int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'code'    => $code,
            'message' => $message,
        ];
        if (!is_null($data)) {
            $response['data'] = $data;
        }
        return response()->json($response, $code);
    }

    /**
     * per_page/limit приходят от клиента без верхней границы — запрос вида
     * ?per_page=999999999 мог бы вынудить базу отдать/посчитать всю таблицу
     * за один раз. Потолок 100 достаточен для любого настоящего экрана
     * списка, а от лишнего заглушки не защитить точнее.
     */
    protected function clampPerPage(int $value, int $max = 100): int
    {
        return max(1, min($value, $max));
    }

    protected function error(string $message, int $code = 400, $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ];
        if (!is_null($errors)) {
            $response['errors'] = $errors;
        }
        return response()->json($response, $code);
    }
}
