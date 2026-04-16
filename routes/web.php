<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/prueba', function () {
    return response()->json([
        'ok' => true,
        'mensaje' => 'API ST JACKS funcionando 🔥'
    ]);
});


Route::get('/api/test-db', function () {

    $data = DB::select("SELECT DATABASE() as db");

    return response()->json([
        'ok' => true,
        'db' => $data
    ]);
});
