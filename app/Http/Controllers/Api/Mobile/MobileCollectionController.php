<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\StorefrontCollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileCollectionController extends Controller
{
    public function __construct(private readonly StorefrontCollectionService $collections) {}

    public function show(Request $request, int $collection)
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'codigoTienda' => ['required', 'string', 'max:30'],
        ]);

        $countryCode = DB::table('stj_paises')
            ->where('pai_id', (int) $data['countryId'])
            ->value('pai_codigo');

        if (! $countryCode) {
            throw ValidationException::withMessages([
                'countryId' => ['El pais seleccionado no existe.'],
            ]);
        }

        $result = $this->collections->find(
            (string) $countryCode,
            $collection,
            (string) $data['codigoTienda'],
        );

        if (! $result) {
            throw ValidationException::withMessages([
                'collection' => ['La coleccion no existe para el pais seleccionado.'],
            ]);
        }

        return response()->json([
            'records' => collect($result['products'])->map(fn (array $product) => [
                'id' => $product['id'],
                'pro_id' => $product['id'],
                'sku' => $product['sku'],
                'marca' => $product['brand'],
                'nombre' => $product['name'],
                'precio' => number_format((float) ($product['previousPrice'] ?? $product['price']), 2, '.', ''),
                'descuento' => $product['discountPercentage'] ?? 0,
                'origen' => $product['promotion']['origin'] ?? '',
                'sello' => $product['promotion']['logoUrl'] ?? '',
                'precioCD' => number_format((float) $product['price'], 2, '.', ''),
                'descripcion' => $product['description'],
                'categoriaTxt' => $product['category'],
                'subCategoriaTxt' => '',
                'foto' => $product['imageUrl'],
                'Domicilio' => true,
                'Tienda' => true,
                'ppa_promo_nombre' => $product['promoName'],
                'hasStock' => $product['hasStock'],
                'stockTotal' => $product['stockTotal'],
            ])->values()->all(),
            'collection' => $result['collection'],
            'availability' => $result['availability'],
        ]);
    }
}
