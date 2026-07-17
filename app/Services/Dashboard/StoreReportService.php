<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
                p.ped_estatus,
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
                    'status' => (string) ($row->ped_estatus ?? ''),
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
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function homeDelivery(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $start = $this->nullableDate($filters['startDate'] ?? null);
        $end = $this->nullableDate($filters['endDate'] ?? null);

        $this->validateDateRange($start, $end);

        $storeCode = $this->domicilioStoreCode($countryId);
        $rows = $this->homeDeliveryRows($countryId, $storeCode, $start, $end);

        return [
            'countries' => $this->countries(),
            'filters' => [
                'country' => $countryId,
                'startDate' => $start,
                'endDate' => $end,
                'store' => $storeCode,
            ],
            'summary' => [
                'orders' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{filename: string, contents: string}
     */
    public function exportHomeDelivery(array $filters): array
    {
        $report = $this->homeDelivery($filters);
        $headers = [
            'STJ',
            'Fecha Pedido',
            'Fecha facturado',
            'Plataforma',
            'Estado Pedido',
            'Tipo checkout',
            'Tienda',
            'Nombres',
            'Apellidos',
            'Correo',
            'Dui',
            'Direccion',
            'Telefono',
            'Whatsapp',
            'Tipo pago',
            'Tarjeta',
            'Pago estado',
        ];
        $keys = [
            'stj',
            'orderDate',
            'processedAt',
            'platform',
            'orderStatus',
            'checkoutType',
            'store',
            'names',
            'lastNames',
            'email',
            'dui',
            'address',
            'phone',
            'whatsapp',
            'paymentType',
            'card',
            'paymentStatus',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Domicilio');
        $sheet->fromArray($headers, null, 'A1');

        $rowNumber = 2;
        foreach ($report['rows'] as $row) {
            $sheet->fromArray(array_map(fn (string $key) => $row[$key] ?? null, $keys), null, 'A'.$rowNumber);
            $rowNumber++;
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, $rowNumber - 1));
        $sheet->freezePane('A2');

        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);
        for ($columnIndex = 1; $columnIndex <= $lastColumnIndex; $columnIndex++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        $filename = 'reporte-domicilio-'.Carbon::parse($report['filters']['startDate'])->format('Ymd').'-'.Carbon::parse($report['filters']['endDate'])->format('Ymd').'.xlsx';
        $path = tempnam(sys_get_temp_dir(), 'stj-domicilio-');

        try {
            (new Xlsx($spreadsheet))->save($path);

            return [
                'filename' => $filename,
                'contents' => (string) file_get_contents($path),
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($path)) {
                unlink($path);
            }
        }
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

    private function validateDateRange(?string $start, ?string $end): void
    {
        if (($start && ! $end) || (! $start && $end)) {
            throw ValidationException::withMessages([
                'endDate' => 'Debe enviar ambas fechas o ninguna.',
            ]);
        }

        if ($start === null || $end === null) {
            throw ValidationException::withMessages([
                'startDate' => 'Debe enviar fecha inicio y fecha fin.',
            ]);
        }

        if ($start > $end) {
            throw ValidationException::withMessages([
                'endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
            ]);
        }
    }

    private function domicilioStoreCode(int $countryId): string
    {
        $countryCode = strtolower((string) DB::table('stj_paises')->where('pai_id', $countryId)->value('pai_codigo'));
        $configured = $countryCode !== ''
            ? (string) config("inventory.domicilio_store_by_country.{$countryCode}", '')
            : '';

        $storeCode = $configured !== ''
            ? $configured
            : match ($countryId) {
                1 => '57',
                2 => '2',
                3 => '1',
                default => '',
            };

        if ($storeCode === '') {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no tiene tienda de domicilio configurada.',
            ]);
        }

        return $storeCode;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function homeDeliveryRows(int $countryId, string $storeCode, string $start, string $end): array
    {
        $startAt = Carbon::parse($start)->startOfDay();
        $endAt = Carbon::parse($end)->addDay()->startOfDay();

        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->join('stj_tiendas as store', function ($join) use ($countryId) {
                $join->on('store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('store.tie_pais', '=', $countryId);
            })
            ->where('pay.ppa_fecha', '>=', $startAt)
            ->where('pay.ppa_fecha', '<', $endAt)
            ->where('p.ped_tienda', $storeCode)
            ->where('pay.ppa_estado', 'APROBADA')
            ->where('p.ped_id_pais', $countryId)
            ->where('p.ped_checkout', 'DOMICILIO')
            ->orderBy('pay.ppa_fecha')
            ->selectRaw('
                pay.ppa_ref,
                pay.ppa_fecha,
                pay.ppa_fecha_procesado,
                p.ped_origen,
                p.ped_estatus,
                p.ped_checkout,
                store.tie_nombre,
                p.ped_nombres,
                p.ped_apellidos,
                p.ped_email,
                p.ped_identificacion,
                p.ped_direccion,
                p.ped_telefono,
                p.ped_whatsapp,
                pay.ppa_tipo,
                pay.ppa_tarjeta,
                pay.ppa_estado
            ')
            ->get()
            ->map(fn ($row) => [
                'stj' => (string) ($row->ppa_ref ?? ''),
                'orderDate' => $this->dateTimeOrNull($row->ppa_fecha ?? null),
                'processedAt' => $this->dateTimeOrNull($row->ppa_fecha_procesado ?? null),
                'platform' => (string) ($row->ped_origen ?? ''),
                'orderStatus' => (string) ($row->ped_estatus ?? ''),
                'checkoutType' => (string) ($row->ped_checkout ?? ''),
                'store' => (string) ($row->tie_nombre ?? ''),
                'names' => (string) ($row->ped_nombres ?? ''),
                'lastNames' => (string) ($row->ped_apellidos ?? ''),
                'email' => (string) ($row->ped_email ?? ''),
                'dui' => (string) ($row->ped_identificacion ?? ''),
                'address' => (string) ($row->ped_direccion ?? ''),
                'phone' => (string) ($row->ped_telefono ?? ''),
                'whatsapp' => (string) ($row->ped_whatsapp ?? ''),
                'paymentType' => (string) ($row->ppa_tipo ?? ''),
                'card' => (string) ($row->ppa_tarjeta ?? ''),
                'paymentStatus' => (string) ($row->ppa_estado ?? ''),
            ])
            ->values()
            ->all();
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
            8 => '$',
            default => '',
        };
    }
}
