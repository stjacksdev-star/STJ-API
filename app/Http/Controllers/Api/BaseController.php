<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class BaseController extends Controller
{
    protected function success($data = [], $message = 'OK')
    {
        return response()->json([
            'ok' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    protected function error($message = 'Error', $code = 400)
    {
        return response()->json([
            'ok' => false,
            'message' => $message
        ], $code);
    }
}