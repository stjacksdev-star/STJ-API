<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileProductService;
use Illuminate\Http\Request;

class MobileProductController extends Controller
{
    public function __construct(private readonly MobileProductService $products) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'categoryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
        ]);

        return response()->json([
            'records' => $this->products->forCategory(
                (int) $data['countryId'],
                (int) $data['categoryId'],
                (string) $data['codigoTienda'],
            ),
        ]);
    }

    public function filter(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'categoria' => ['required', 'integer', 'min:1'],
            'scat' => ['nullable', 'integer', 'min:1'],
            'sub_id' => ['nullable'],
            'ordenamiento' => ['nullable', 'string', 'max:60'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'gte:min'],
            'talla' => ['nullable', 'string', 'max:30'],
            'tienda' => ['required', 'string', 'max:30'],
            'pais' => ['nullable'],
        ]);

        return response()->json(
            $this->products->filter((int) $data['countryId'], $data)
        );
    }

    public function filterJackCo(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'categoria' => ['required', 'integer', 'min:1'],
            'scat' => ['nullable', 'integer', 'min:1'],
            'ordenamiento' => ['nullable', 'string', 'max:60'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'gte:min'],
            'talla' => ['nullable', 'string', 'max:30'],
            'pais' => ['nullable'],
        ]);

        return response()->json([
            'records' => $this->products->filterJackCo((int) $data['countryId'], $data),
        ]);
    }

    public function filterBasikos(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'categoria' => ['required', 'integer', 'min:1'],
            'scat' => ['nullable', 'integer', 'min:1'],
            'ordenamiento' => ['nullable', 'string', 'max:60'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'gte:min'],
            'talla' => ['nullable', 'string', 'max:30'],
            'pais' => ['nullable'],
            'tienda' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json([
            'records' => $this->products->filterBasikos((int) $data['countryId'], $data),
        ]);
    }
}
