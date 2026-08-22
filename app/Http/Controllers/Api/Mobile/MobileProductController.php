<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileProductService;
use Illuminate\Http\Request;

class MobileProductController extends Controller
{
    public function __construct(private readonly MobileProductService $products) {}

    public function filter(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'categoria' => ['required', 'integer', 'min:1'],
            'scat' => ['nullable'],
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
}
