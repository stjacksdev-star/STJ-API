<?php

namespace App\Services;

use App\Services\Inventory\InventorySourceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontFulfillmentService
{
    public function __construct(private InventorySourceResolver $sources) {}

    public function resolve(int $countryId, string $countryCode, array $selection): array
    {
        $type = strtoupper(trim((string) ($selection['fulfillment_type'] ?? '')));
        if (! in_array($type, ['DOMICILIO', 'TIENDA'], true)) {
            throw ValidationException::withMessages(['fulfillment_type' => 'Servicio de entrega invalido.']);
        }
        $code = $type === 'DOMICILIO' ? (string) config('inventory.domicilio_store_by_country.'.strtolower($countryCode)) : trim((string) ($selection['store_code'] ?? ''));
        if ($code === '') {
            throw ValidationException::withMessages(['store_code' => $type === 'TIENDA' ? 'Debe seleccionar una tienda.' : 'No existe fuente de domicilio para el pais.']);
        }
        $query = DB::table('stj_tiendas')->where('tie_pais', $countryId)->where('tie_codigo', $code);
        if ($type === 'TIENDA') {
            $query->where('tie_productos', 1);
        }
        $store = $query->first(['tie_id', 'tie_codigo', 'tie_nombre', 'tie_productos']);
        if (! $store) {
            throw ValidationException::withMessages(['store_code' => 'La tienda no pertenece al pais o no esta habilitada.']);
        }
        $rule = $this->sources->resolve(strtolower($countryCode), 'cart');

        return ['type' => $type, 'storeId' => (int) $store->tie_id, 'storeCode' => (string) $store->tie_codigo, 'storeName' => $type === 'DOMICILIO' ? 'Domicilio' : trim((string) $store->tie_nombre), 'inventorySource' => $rule['source'], 'fallbackSource' => $rule['fallback_source']];
    }
}
