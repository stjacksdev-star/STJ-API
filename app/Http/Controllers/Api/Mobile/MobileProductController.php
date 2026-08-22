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

    public function show(Request $request, int $product)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
        ]);

        return response()->json($this->products->detail(
            (int) $data['countryId'],
            $product,
            (string) $data['codigoTienda'],
        ));
    }

    public function sizes(Request $request, string $sku)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
        ]);

        return response()->json($this->products->sizes(
            (int) $data['countryId'],
            $sku,
            (string) $data['codigoTienda'],
        ));
    }

    public function suggestions(Request $request, int $product)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'idUser' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($this->products->suggestions(
            (int) $data['countryId'],
            $product,
            (string) $data['codigoTienda'],
        ));
    }

    public function photos(Request $request, int $product)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'records' => $this->products->photos((int) $data['countryId'], $product),
        ]);
    }

    public function favoriteStatus(Request $request, int $product)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'idUser' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->products->favoriteStatus(
            (int) $data['countryId'],
            $product,
            (int) $data['idUser'],
        ));
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
