<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Services\Mobile\MobileProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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

    public function search(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'categoryId' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'records' => $this->products->search(
                (int) $data['countryId'],
                (string) $data['q'],
                (string) $data['codigoTienda'],
                isset($data['categoryId']) ? (int) $data['categoryId'] : null,
            ),
        ]);
    }

    public function barcode(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'codigo' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        return response()->json($this->products->barcode(
            (int) $data['countryId'],
            (string) $data['codigo'],
            (string) $data['codigoTienda'],
        ));
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

    public function setFavorite(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'idUser' => ['nullable', 'integer', 'min:1'],
            'producto' => ['required', 'integer', 'min:1'],
            'estado' => ['nullable', 'string', Rule::in(['ACTIVO', 'INACTIVO', 'activo', 'inactivo'])],
            'categoria' => ['nullable'],
            'subCategoria' => ['nullable'],
        ]);

        $authenticated = Auth::guard('sanctum')->user();
        $userId = $authenticated instanceof StorefrontCustomer
            ? (int) $authenticated->getKey()
            : (int) ($data['idUser'] ?? 0);

        if ($userId < 1) {
            return response()->json([
                'resultado' => false,
                'mensaje' => 'Debes iniciar sesion.',
            ]);
        }

        return response()->json($this->products->setFavorite(
            (int) $data['countryId'],
            (int) $data['producto'],
            $userId,
            strtoupper((string) ($data['estado'] ?? 'ACTIVO')),
            (string) $request->query('plataforma', 'MOBILE'),
            $data,
        ));
    }

    public function favorites(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'idUser' => ['nullable', 'integer', 'min:1'],
        ]);

        $authenticated = Auth::guard('sanctum')->user();
        $userId = $authenticated instanceof StorefrontCustomer
            ? (int) $authenticated->getKey()
            : (int) ($data['idUser'] ?? 0);

        if ($userId < 1) {
            return response()->json([
                'resultado' => false,
                'mensaje' => 'Debes iniciar sesion.',
                'records' => [],
            ]);
        }

        return response()->json([
            'records' => $this->products->favorites(
                (int) $data['countryId'],
                $userId,
                (string) $data['codigoTienda'],
            ),
            'resultado' => true,
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
