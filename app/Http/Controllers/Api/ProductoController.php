<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;

class ProductoController extends BaseController
{
    public function listar()
    {
        $productos = DB::select("SELECT * FROM stj_productos LIMIT 10");

        return $this->success($productos, 'Productos obtenidos');
    }
}