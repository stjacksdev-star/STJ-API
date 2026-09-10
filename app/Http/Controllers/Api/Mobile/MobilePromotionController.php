<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobilePromotionService;
use App\Services\StorefrontPromotionLandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobilePromotionController extends Controller
{
    public function __construct(
        private readonly MobilePromotionService $promotions,
        private readonly StorefrontPromotionLandingService $landing,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'exists:stj_paises,pai_id'],
            'plataforma' => ['nullable', Rule::in(['IOS', 'ANDROID'])],
        ]);

        return response()->json([
            'records' => $this->promotions->forCountry((int) $data['countryId']),
        ]);
    }

    public function show(Request $request, int $promotion)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'exists:stj_paises,pai_id'],
            'codigoTienda' => ['required', 'string', 'max:30'],
            'tipoServicio' => ['nullable', 'string', 'max:30'],
            'plataforma' => ['nullable', Rule::in(['IOS', 'ANDROID'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['featured', 'discount_desc', 'discount_asc', 'newest', 'price_asc', 'price_desc'])],
        ]);
        $countryCode = DB::table('stj_paises')->where('pai_id', $data['countryId'])->value('pai_codigo');
        $result = $this->landing->find((string) $countryCode, $promotion, [
            'page' => (int) ($data['page'] ?? 1),
            'perPage' => (int) ($data['perPage'] ?? 48),
            'brand' => $data['brand'] ?? '',
            'gender' => $data['gender'] ?? '',
            'sort' => $data['sort'] ?? 'featured',
            'checkoutType' => strtoupper((string) ($data['tipoServicio'] ?? 'DOMICILIO')),
            'storeCode' => (string) $data['codigoTienda'],
            'channel' => 'APP',
            'platform' => strtoupper((string) ($data['plataforma'] ?? '')),
        ]);

        if (! $result) {
            throw ValidationException::withMessages([
                'promotion' => ['La promocion no existe o no esta activa para el pais seleccionado.'],
            ]);
        }

        $records = collect($result['products'] ?? [])->map(fn (array $product) => [
            ...$product,
            'id' => $product['id'],
            'pro_id' => $product['id'],
            'sku' => $product['sku'],
            'marca' => $product['brand'],
            'nombre' => $product['name'],
            'precio' => number_format((float) ($product['previousPrice'] ?? $product['price']), 2, '.', ''),
            'precioCD' => number_format((float) $product['price'], 2, '.', ''),
            'descuento' => $product['discountPercentage'] ?? 0,
            'origen' => $product['promotion']['origin'] ?? '',
            'sello' => $product['promotion']['logoUrl'] ?? '',
            'categoriaTxt' => $product['category'],
            'categoria' => $product['categoryId'],
            'subCategoria' => $product['subcategoryId'],
            'subCategoriaTxt' => $product['subcategory'] ?? '',
            'foto' => $product['imageUrl'],
            'ppa_promo_nombre' => $product['promoName'],
            'hasStock' => $product['hasStock'],
            'stockTotal' => $product['stockTotal'],
        ])->values()->all();

        return response()->json([
            ...$result,
            'records' => $records,
        ]);
    }
}
