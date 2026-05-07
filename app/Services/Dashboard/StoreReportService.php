<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreReportService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function virtualCut(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $store = $this->resolveStore($countryId, $filters['store'] ?? null);
        $date = Carbon::parse((string) ($filters['date'] ?? now()->toDateString()))->toDateString();

        if (! $store) {
            throw ValidationException::withMessages([
                'store' => 'Debe seleccionar una tienda.',
            ]);
        }

        $rows = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->join('stj_tiendas as store', function ($join) use ($countryId) {
                $join->on('store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_pedidos_direccion as address', 'address.pdi_pedido', '=', 'p.ped_id')
            ->where('p.ped_id_pais', $countryId)
            ->where('store.tie_id', $store['id'])
            ->whereDate('pay.ppa_fecha', $date)
            ->orderBy('pay.ppa_fecha')
            ->selectRaw('
                p.ped_id,
                p.ped_monto_devolucion,
                pay.ppa_fecha,
                pay.ppa_tipo,
                pay.ppa_ref,
                pay.ppa_ticket,
                pay.ppa_fecha_procesado,
                pay.ppa_autorizacion,
                pay.ppa_monto,
                pay.ppa_monto_senv,
                address.pdi_costo_envio_final
            ')
            ->get()
            ->map(function ($row) {
                $refund = (float) ($row->ped_monto_devolucion ?? 0);
                $paymentAmount = (float) ($row->ppa_monto ?? 0);

                return [
                    'orderId' => (int) $row->ped_id,
                    'purchaseDate' => $this->dateTimeOrNull($row->ppa_fecha),
                    'paymentType' => (string) ($row->ppa_tipo ?? ''),
                    'reference' => (string) ($row->ppa_ref ?? ''),
                    'ticket' => (string) ($row->ppa_ticket ?? ''),
                    'processedAt' => $this->dateTimeOrNull($row->ppa_fecha_procesado),
                    'authorization' => (string) ($row->ppa_autorizacion ?? ''),
                    'chargedAmount' => (float) ($row->ppa_monto_senv ?? 0),
                    'shipping' => (float) ($row->pdi_costo_envio_final ?? 0),
                    'refund' => $refund,
                    'total' => round($paymentAmount - $refund, 2),
                ];
            })
            ->values()
            ->all();

        $card = 0.0;
        $cash = 0.0;

        foreach ($rows as $row) {
            if (strtoupper((string) $row['paymentType']) === 'TARJETA') {
                $card += (float) $row['total'];
            } else {
                $cash += (float) $row['total'];
            }
        }

        return [
            'countries' => $this->countries(),
            'stores' => $this->stores($countryId),
            'filters' => [
                'country' => $countryId,
                'date' => $date,
                'store' => $store['code'],
                'storeId' => $store['id'],
                'storeName' => $store['name'],
            ],
            'currency' => [
                'symbol' => $this->currencySymbol($countryId),
            ],
            'summary' => [
                'transactions' => count($rows),
                'card' => round($card, 2),
                'cash' => round($cash, 2),
                'total' => round($card + $cash, 2),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function pendingItems(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $type = strtoupper(trim((string) ($filters['type'] ?? 'TIENDA')));
        $store = $this->resolveStore($countryId, $filters['store'] ?? null);
        $start = $this->nullableDate($filters['startDate'] ?? null);
        $end = $this->nullableDate($filters['endDate'] ?? null);

        if (! in_array($type, ['DOMICILIO', 'TIENDA'], true)) {
            throw ValidationException::withMessages([
                'type' => 'El tipo seleccionado no es valido.',
            ]);
        }

        if (($start && ! $end) || (! $start && $end)) {
            throw ValidationException::withMessages([
                'endDate' => 'Debe enviar ambas fechas o ninguna.',
            ]);
        }

        if ($start !== null && $end !== null && $start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        if ($type === 'TIENDA' && ! $store) {
            throw ValidationException::withMessages([
                'store' => 'Debe seleccionar una tienda.',
            ]);
        }

        $rows = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->join('stj_pedidos_detalle as detail', 'detail.car_ref', '=', 'pay.ppa_ref')
            ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->where('p.ped_id_pais', $countryId)
            ->where('p.ped_estatus', 'RECIBIDO')
            ->where('detail.car_accion', 'AGREGADO')
            ->where('p.ped_checkout', $type)
            ->when($type === 'DOMICILIO', fn ($query) => $query->where('p.ped_tienda', '57'))
            ->when($type === 'TIENDA', fn ($query) => $query->where('p.ped_tienda', $store['code']))
            ->when($start !== null, fn ($query) => $query->whereDate('pay.ppa_fecha', '>=', $start))
            ->when($end !== null, fn ($query) => $query->whereDate('pay.ppa_fecha', '<=', $end))
            ->groupBy('product.pro_nombre', 'product.pro_codigo', 'detail.car_talla')
            ->orderBy('product.pro_codigo')
            ->selectRaw('
                product.pro_nombre,
                product.pro_codigo,
                detail.car_talla,
                SUM(detail.car_cantidad) AS total
            ')
            ->get()
            ->map(fn ($row) => [
                'product' => (string) ($row->pro_nombre ?? ''),
                'sku' => (string) ($row->pro_codigo ?? ''),
                'size' => (string) ($row->car_talla ?? ''),
                'quantity' => (int) ($row->total ?? 0),
                'imageUrl' => $this->productImageUrl((string) ($row->pro_codigo ?? '').'.jpg', 'productos_thums'),
            ])
            ->values()
            ->all();

        return [
            'countries' => $this->countries(),
            'stores' => $this->stores($countryId),
            'filters' => [
                'country' => $countryId,
                'type' => $type,
                'startDate' => $start,
                'endDate' => $end,
                'store' => $store['code'] ?? null,
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? null,
            ],
            'summary' => [
                'rows' => count($rows),
                'quantity' => array_sum(array_column($rows, 'quantity')),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function pendingItemsByOrder(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $store = $this->resolveStore($countryId, $filters['store'] ?? null);
        $start = $this->nullableDate($filters['startDate'] ?? null);
        $end = $this->nullableDate($filters['endDate'] ?? null);

        if (($start && ! $end) || (! $start && $end)) {
            throw ValidationException::withMessages([
                'endDate' => 'Debe enviar ambas fechas o ninguna.',
            ]);
        }

        if ($start !== null && $end !== null && $start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }

        $rows = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->join('stj_pedidos_detalle as detail', 'detail.car_ref', '=', 'pay.ppa_ref')
            ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->where('p.ped_id_pais', $countryId)
            ->where('p.ped_estatus', 'RECIBIDO')
            ->where('detail.car_accion', 'AGREGADO')
            ->when($store['code'] ?? null, fn ($query, string $storeCode) => $query->where('p.ped_tienda', $storeCode))
            ->when($start !== null, fn ($query) => $query->whereDate('pay.ppa_fecha', '>=', $start))
            ->when($end !== null, fn ($query) => $query->whereDate('pay.ppa_fecha', '<=', $end))
            ->groupBy('detail.car_ref', 'pay.ppa_fecha', 'product.pro_nombre', 'product.pro_codigo', 'detail.car_talla')
            ->orderBy('pay.ppa_fecha')
            ->selectRaw('
                detail.car_ref,
                pay.ppa_fecha,
                product.pro_nombre,
                product.pro_codigo,
                detail.car_talla,
                SUM(detail.car_cantidad) AS total
            ')
            ->get()
            ->map(fn ($row) => [
                'reference' => (string) ($row->car_ref ?? ''),
                'paidAt' => $this->dateTimeOrNull($row->ppa_fecha ?? null),
                'product' => (string) ($row->pro_nombre ?? ''),
                'sku' => (string) ($row->pro_codigo ?? ''),
                'size' => (string) ($row->car_talla ?? ''),
                'quantity' => (int) ($row->total ?? 0),
                'imageUrl' => $this->productImageUrl((string) ($row->pro_codigo ?? '').'.jpg', 'productos_thums'),
            ])
            ->values()
            ->all();

        return [
            'countries' => $this->countries(),
            'filters' => [
                'country' => $countryId,
                'startDate' => $start,
                'endDate' => $end,
                'store' => $store['code'] ?? null,
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? null,
            ],
            'summary' => [
                'orders' => count(array_unique(array_column($rows, 'reference'))),
                'rows' => count($rows),
                'quantity' => array_sum(array_column($rows, 'quantity')),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_nombre')
            ->get()
            ->map(fn ($country) => [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function storesForCountry(string $country): array
    {
        return $this->stores($this->resolveCountryId($country));
    }

    private function resolveCountryId(string $country): int
    {
        $country = trim($country);
        $query = DB::table('stj_paises')->select(['pai_id']);

        $resolved = is_numeric($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return (int) $resolved->pai_id;
    }

    /**
     * @return array{code: string, id: int, name: string}|null
     */
    private function resolveStore(int $countryId, mixed $store): ?array
    {
        $store = trim((string) $store);

        if ($store === '') {
            return null;
        }

        $query = DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId);

        $resolved = (clone $query)->where('tie_codigo', $store)->first();

        if (! $resolved && is_numeric($store)) {
            $resolved = (clone $query)->where('tie_id', (int) $store)->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages([
                'store' => 'La tienda seleccionada no existe para el pais indicado.',
            ]);
        }

        return [
            'id' => (int) $resolved->tie_id,
            'code' => (string) $resolved->tie_codigo,
            'name' => trim((string) $resolved->tie_nombre),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stores(int $countryId): array
    {
        return DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId)
            ->orderBy('tie_nombre')
            ->get()
            ->map(fn ($store) => [
                'storeId' => (int) $store->tie_id,
                'storeCode' => (string) $store->tie_codigo,
                'store' => trim((string) $store->tie_nombre).' ('.(string) $store->tie_codigo.')',
            ])
            ->values()
            ->all();
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    private function productImageUrl(string $filename, string $folder): string
    {
        $filename = trim($filename);

        if ($filename === '') {
            return '';
        }

        $spacesUrl = rtrim((string) config('filesystems.disks.spaces.url'), '/');

        if ($spacesUrl !== '') {
            return $spacesUrl.'/images/'.$folder.'/'.ltrim($filename, '/');
        }

        return 'https://stjacks.com/images/'.$folder.'/'.ltrim($filename, '/');
    }

    private function currencySymbol(int $countryId): string
    {
        return match ($countryId) {
            1 => '$',
            2 => 'Q',
            3 => 'CRC',
            7 => 'L',
            default => '',
        };
    }
}
