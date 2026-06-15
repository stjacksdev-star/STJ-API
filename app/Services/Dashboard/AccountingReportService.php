<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AccountingReportService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function salesByStore(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $start = $this->nullableDate($filters['startDate'] ?? null);
        $end = $this->nullableDate($filters['endDate'] ?? null);
        $store = $this->resolveStore($countryId, $filters['store'] ?? '0');

        if (($start && ! $end) || (! $start && $end)) {
            throw ValidationException::withMessages(['endDate' => 'Debe enviar ambas fechas o ninguna.']);
        }

        if ($start !== null && $end !== null && $start > $end) {
            throw ValidationException::withMessages(['endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.']);
        }

        $orders = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->join('stj_tiendas as store', function ($join) use ($countryId) {
                $join->on('store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('store.tie_pais', '=', $countryId);
            })
            ->where('p.ped_id_pais', $countryId)
            ->whereDate('pay.ppa_fecha', '>=', $start)
            ->whereDate('pay.ppa_fecha', '<=', $end)
            ->when(($store['code'] ?? '0') !== '0', fn ($query) => $query->where('p.ped_tienda', $store['code']))
            ->orderBy('pay.ppa_fecha')
            ->selectRaw('
                p.ped_checkout,
                p.ped_nombres,
                p.ped_apellidos,
                p.ped_estatus,
                p.ped_estatus_productos,
                p.ped_devolucion_realizada,
                p.ped_monto_devolucion,
                pay.ppa_fecha,
                pay.ppa_ref,
                pay.ppa_ticket,
                pay.ppa_autorizacion,
                pay.ppa_articulos,
                pay.ppa_monto,
                pay.ppa_monto_senv,
                store.tie_nombre
            ')
            ->get();
        $details = $this->detailsByReference($orders->pluck('ppa_ref')->map(fn ($ref) => (string) $ref)->all());
        $rows = $orders
            ->map(function ($row) use ($details) {
                $refund = $this->refundAmount($row);
                $orderDetails = $details[(string) ($row->ppa_ref ?? '')] ?? collect();

                return [
                    'paidAt' => $this->dateTimeOrNull($row->ppa_fecha ?? null),
                    'store' => (string) ($row->ped_checkout ?? '') === 'DOMICILIO' ? 'DOMICILIO' : (string) ($row->tie_nombre ?? ''),
                    'reference' => (string) ($row->ppa_ref ?? ''),
                    'customer' => mb_strtoupper(trim((string) ($row->ped_nombres ?? '').' '.(string) ($row->ped_apellidos ?? ''))),
                    'ticket' => (string) ($row->ppa_ticket ?? ''),
                    'authorization' => (string) ($row->ppa_autorizacion ?? ''),
                    'items' => $this->originalItems($orderDetails, (int) ($row->ppa_articulos ?? 0)),
                    'amount' => (float) ($row->ppa_monto_senv ?? 0),
                    'status' => (string) ($row->ped_estatus ?? ''),
                    'productStatus' => (string) ($row->ped_estatus_productos ?? ''),
                    'refundStatus' => (string) ($row->ped_devolucion_realizada ?? ''),
                    'refund' => $refund,
                    'hasRefund' => (string) ($row->ped_devolucion_realizada ?? '') !== 'N/A',
                ];
            })
            ->values()
            ->all();

        return [
            'filters' => [
                'country' => $countryId,
                'countryName' => $this->countryName($countryId),
                'company' => $this->companyName($countryId),
                'store' => $store['code'] ?? '0',
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? 'Todas',
                'startDate' => $start,
                'endDate' => $end,
            ],
            'currency' => [
                'symbol' => $this->currencySymbol($countryId),
            ],
            'summary' => [
                'orders' => count($rows),
                'items' => array_sum(array_column($rows, 'items')),
                'amount' => array_sum(array_column($rows, 'amount')),
                'refund' => array_sum(array_column($rows, 'refund')),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function countAccounting3(array $filters): array
    {
        $prepared = $this->prepareFilters($filters);

        return [
            'filters' => $this->filterPayload($prepared),
            'summary' => [
                'orders' => $this->ordersQuery($prepared)->count(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{contents: string, filename: string}
     */
    public function exportAccounting3(array $filters): array
    {
        $prepared = $this->prepareFilters($filters);
        $orders = $this->ordersQuery($prepared)->get();
        $details = $this->detailsByReference($orders->pluck('ppa_ref')->map(fn ($ref) => (string) $ref)->all());

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $lastColumn = 'AD';
        $headerRow = 9;
        $startRow = 10;

        $spreadsheet->getProperties()
            ->setCreator("Desarrollo St. Jacks")
            ->setLastModifiedBy("Desarrollo St. Jacks")
            ->setTitle('Venta contabilidad')
            ->setSubject('Venta contabilidad')
            ->setDescription('Reporte de venta para contabilidad.')
            ->setKeywords('St.Jacks')
            ->setCategory('Procesos Autoservicio');

        $sheet->fromArray([
            ['Reporte de venta eCommerce'],
            ['Generado: '.$this->spanishDateTime(now())],
            ['Tipo de pago: '.$prepared['paymentType']],
            ['Estado: '.$prepared['status']],
            ['Pais: '.$prepared['countryName']],
            ['Tienda: '.$prepared['storeName']],
            ['Fecha: '.$this->dateRangeLabel($prepared['startDate'], $prepared['endDate'])],
        ], null, 'A1');

        $headers = [
            'Fecha Pedido',
            'Tienda',
            'Pedido',
            'Plataforma',
            'Autorizacion',
            'Tipo de pago',
            'DTE',
            'Fecha Procesamiento',
            'Devolucion Realizada',
            'DTE NC',
            'Fecha Devolucion',
            'Cliente',
            'SKU',
            'Cantidad',
            'Precio Unitario',
            'Descuento',
            'Cantidad Facturado',
            'Descuento Facturado',
            'SubTotal',
            'Facturado',
            'Descuento extra',
            'Promocion',
            'Cupon',
            'Cobro',
            'Envio',
            'Facturado',
            'Devolucion',
            'Estatus Pedido',
            'Estatus Articulos',
            'Metodo de entrega',
        ];
        $sheet->fromArray($headers, null, 'A'.$headerRow);

        $row = $startRow;
        $currentDay = '';
        $daySubtotalList = 0.0;
        $daySubtotalBilled = 0.0;
        $dayShippingList = 0.0;
        $dayShippingBilled = 0.0;

        foreach ($orders as $order) {
            $day = $this->spanishDate((string) $order->ppa_fecha);

            if ($currentDay !== '' && $day !== $currentDay) {
                $row = $this->writeDayTotals($sheet, $row, $currentDay, $daySubtotalList, $daySubtotalBilled, $dayShippingList, $dayShippingBilled);
                $daySubtotalList = $daySubtotalBilled = $dayShippingList = $dayShippingBilled = 0.0;
            }

            $currentDay = $day;
            $orderDetails = $details[(string) $order->ppa_ref] ?? collect();
            $lineSubtotalList = 0.0;
            $lineSubtotalBilled = 0.0;
            $line = 0;

            foreach ($orderDetails as $detail) {
                $quantity = $this->originalQuantity($detail);
                $billedQuantity = (int) ($detail->car_total_facturado ?? 0);
                $price = (float) ($detail->car_precio ?? 0);
                $discount = (float) ($detail->car_descuento ?? 0);
                $billedDiscount = (float) ($detail->car_descuento_final ?? 0);
                $subtotalList = $quantity * ($price * (1 - ($discount / 100)));
                $subtotalBilled = $billedQuantity * ($price * (1 - ($billedDiscount / 100)));

                if ($line === 0) {
                    $sheet->fromArray([
                        (string) $order->ppa_fecha,
                        (string) $order->tie_nombre,
                        (string) $order->ppa_ref,
                        (string) ($order->ped_origen ?? ''),
                        (string) ($order->ppa_autorizacion ?? ''),
                        (string) ($order->ppa_tipo ?? ''),
                        (string) ($order->ppa_ticket ?? ''),
                        (string) ($order->ppa_fecha_procesado ?? ''),
                        (string) ($order->ped_devolucion_realizada ?? ''),
                        '',
                        (string) ($order->ped_fecha_devolucion_sistema ?? ''),
                        ucwords(strtolower(trim((string) ($order->ped_nombres ?? '').' '.(string) ($order->ped_apellidos ?? '')))),
                        (string) ($detail->pro_codigo ?? '').'-'.(string) ($detail->car_talla ?? ''),
                        $quantity,
                        $price,
                        number_format($discount, 2).'%',
                        $billedQuantity,
                        number_format($billedDiscount, 2).'%',
                        $subtotalList,
                        $subtotalBilled,
                        number_format((float) ($order->ppa_promo_descuento ?? 0), 2).'%',
                        (string) ($order->ppa_promo_nombre ?? ''),
                        (string) ($order->ppa_cupon ?? ''),
                        (float) ($order->ppa_monto ?? 0),
                        (float) ($order->pdi_costo_envio_final ?? 0),
                        (float) ($order->ppa_monto_final ?? 0),
                        (float) ($order->ped_monto_devolucion ?? 0),
                        (string) ($order->ped_estatus ?? ''),
                        (string) ($order->ped_estatus_productos ?? ''),
                        (string) ($order->ped_checkout ?? ''),
                    ], null, 'A'.$row);
                } else {
                    $sheet->fromArray([
                        null, null, null, null, null, null, null, null, null, null, null, null,
                        (string) ($detail->pro_codigo ?? '').'-'.(string) ($detail->car_talla ?? ''),
                        $quantity,
                        $price,
                        number_format($discount, 2).'%',
                        $billedQuantity,
                        number_format($billedDiscount, 2).'%',
                        $subtotalList,
                        $subtotalBilled,
                    ], null, 'A'.$row);
                }

                $lineSubtotalList += $subtotalList;
                $lineSubtotalBilled += $subtotalBilled;
                $line++;
                $row++;
            }

            $shipping = (float) ($order->ppa_monto ?? 0) - (float) ($order->ppa_monto_senv ?? 0);
            $row = $this->writeOrderTotals($sheet, $row, $lineSubtotalList, $lineSubtotalBilled, $shipping);

            $daySubtotalList += $lineSubtotalList;
            $daySubtotalBilled += $lineSubtotalBilled;
            $dayShippingList += $shipping;
            $dayShippingBilled += $shipping;
        }

        if ($currentDay !== '') {
            $row = $this->writeDayTotals($sheet, $row, $currentDay, $daySubtotalList, $daySubtotalBilled, $dayShippingList, $dayShippingBilled);
        }

        $sheet->mergeCells('A1:C1');
        $sheet->mergeCells('A2:C2');
        $sheet->mergeCells('A3:C3');
        $sheet->mergeCells('A4:C4');
        $sheet->mergeCells('A5:C5');
        $sheet->mergeCells('A6:C6');
        $sheet->mergeCells('A7:C7');
        $sheet->freezePane('A'.$startRow);
        $sheet->getSheetView()->setZoomScale(75);
        $sheet->setTitle('VentaDetalle');

        $sheet->getStyle('A1')->getFont()->setSize(15);
        $sheet->getStyle('A1:A'.($startRow - 2))->getFont()->setItalic(true);
        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow)->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
        $sheet->getStyle('A'.$startRow.':'.$lastColumn.max($row, $startRow))->getFont()->setSize(11);
        $sheet->getStyle('O'.$startRow.':O'.max($row, $startRow))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('S'.$startRow.':AA'.max($row, $startRow))->getNumberFormat()->setFormatCode('#,##0.00');

        for ($index = 1; $index <= Coordinate::columnIndexFromString($lastColumn); $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return [
            'contents' => $contents,
            'filename' => 'VentaContabilidad.xlsx',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function prepareFilters(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $countryName = $this->countryName($countryId);
        $start = $this->nullableDate($filters['startDate'] ?? null);
        $end = $this->nullableDate($filters['endDate'] ?? null);
        $paymentType = strtoupper(trim((string) ($filters['paymentType'] ?? 'TODO')));
        $status = strtoupper(trim((string) ($filters['status'] ?? 'TODO')));
        $store = $this->resolveStore($countryId, $filters['store'] ?? 'TODO');

        if (! in_array($paymentType, ['TARJETA', 'EFECTIVO', 'TODO'], true)) {
            throw ValidationException::withMessages(['paymentType' => 'El tipo de pago no es valido.']);
        }

        if (! in_array($status, ['FACTURADO', 'PENDIENTE', 'TODO'], true)) {
            throw ValidationException::withMessages(['status' => 'El estado no es valido.']);
        }

        if (($start && ! $end) || (! $start && $end)) {
            throw ValidationException::withMessages(['endDate' => 'Debe enviar ambas fechas o ninguna.']);
        }

        if ($start !== null && $end !== null && $start > $end) {
            throw ValidationException::withMessages(['endDate' => 'La fecha fin debe ser mayor o igual a la fecha inicio.']);
        }

        return [
            'countryId' => $countryId,
            'countryName' => $countryName,
            'startDate' => $start,
            'endDate' => $end,
            'paymentType' => $paymentType,
            'status' => $status,
            'store' => $store,
            'storeName' => $store['name'] ?? 'TODO',
        ];
    }

    /**
     * @param array<string, mixed> $prepared
     */
    private function ordersQuery(array $prepared)
    {
        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->join('stj_tiendas as store', function ($join) use ($prepared) {
                $join->on('store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('store.tie_pais', '=', $prepared['countryId']);
            })
            ->leftJoin('stj_pedidos_direccion as address', 'address.pdi_pedido', '=', 'p.ped_id')
            ->where('p.ped_id_pais', $prepared['countryId'])
            ->whereDate('pay.ppa_fecha', '>=', $prepared['startDate'])
            ->whereDate('pay.ppa_fecha', '<=', $prepared['endDate'])
            ->when($prepared['paymentType'] !== 'TODO', fn ($query) => $query->where('pay.ppa_tipo', $prepared['paymentType']))
            ->when(($prepared['store']['code'] ?? 'TODO') !== 'TODO', fn ($query) => $query->where('p.ped_tienda', $prepared['store']['code']))
            ->when($prepared['status'] === 'FACTURADO', fn ($query) => $query->whereNotIn('p.ped_estatus', ['RECIBIDO']))
            ->when($prepared['status'] === 'PENDIENTE', fn ($query) => $query->whereIn('p.ped_estatus', ['RECIBIDO']))
            ->orderBy('pay.ppa_fecha')
            ->selectRaw('
                p.*,
                pay.*,
                store.tie_nombre,
                store.tie_codigo,
                address.pdi_costo_envio_final
            ');
    }

    /**
     * @param array<int, string> $references
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, object>>
     */
    private function detailsByReference(array $references)
    {
        if ($references === []) {
            return collect();
        }

        $firstLogIds = DB::table('stj_pedidos_detalle_log')
            ->selectRaw('pdl_detalle_id, MIN(pdl_id) AS first_log_id')
            ->groupBy('pdl_detalle_id');

        return DB::table('stj_pedidos_detalle as detail')
            ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->leftJoinSub($firstLogIds, 'first_detail_log', function ($join) {
                $join->on('first_detail_log.pdl_detalle_id', '=', 'detail.car_id');
            })
            ->leftJoin('stj_pedidos_detalle_log as quantity_log', 'quantity_log.pdl_id', '=', 'first_detail_log.first_log_id')
            ->whereIn('detail.car_ref', $references)
            ->where('detail.car_accion', 'AGREGADO')
            ->selectRaw('
                detail.car_id,
                detail.car_ref,
                detail.car_talla,
                detail.car_cantidad,
                detail.car_cantidad_copia,
                detail.car_precio,
                detail.car_descuento,
                detail.car_total_facturado,
                detail.car_descuento_final,
                quantity_log.pdl_cantidad_anterior AS logged_original_quantity,
                product.pro_codigo
            ')
            ->get()
            ->groupBy(fn ($detail) => (string) $detail->car_ref);
    }

    private function originalItems($details, int $fallback): int
    {
        if ($details->isEmpty()) {
            return $fallback;
        }

        return (int) $details->sum(fn ($detail) => $this->originalQuantity($detail));
    }

    private function originalQuantity(object $detail): int
    {
        if ((int) ($detail->car_cantidad_copia ?? 0) > 0) {
            return (int) $detail->car_cantidad_copia;
        }

        if ($detail->logged_original_quantity !== null) {
            return (int) $detail->logged_original_quantity;
        }

        return (int) ($detail->car_cantidad ?? 0);
    }

    private function writeOrderTotals($sheet, int $row, float $subtotalList, float $subtotalBilled, float $shipping): int
    {
        $sheet->setCellValue('R'.$row, 'SUB-TOTAL')
            ->setCellValue('S'.$row, $subtotalList)
            ->setCellValue('T'.$row, $subtotalBilled);
        $sheet->getStyle('R'.$row.':T'.$row)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('R'.$row.':T'.$row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('R'.$row, 'ENVIO')
            ->setCellValue('S'.$row, $shipping)
            ->setCellValue('T'.$row, $shipping);
        $sheet->getStyle('R'.$row.':T'.$row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('R'.$row, 'TOTAL')
            ->setCellValue('S'.$row, $subtotalList + $shipping)
            ->setCellValue('T'.$row, $subtotalBilled + $shipping);
        $sheet->getStyle('R'.$row.':T'.$row)->getFont()->setBold(true);

        return $row + 2;
    }

    private function writeDayTotals($sheet, int $row, string $day, float $subtotalList, float $subtotalBilled, float $shippingList, float $shippingBilled): int
    {
        $sheet->setCellValue('N'.$row, 'Total dia '.$day)
            ->setCellValue('R'.$row, 'SUB-TOTAL')
            ->setCellValue('S'.$row, $subtotalList)
            ->setCellValue('T'.$row, $subtotalBilled);
        $sheet->mergeCells('N'.$row.':Q'.$row);
        $sheet->getStyle('N'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('N'.$row.':T'.$row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('R'.$row, 'ENVIO')
            ->setCellValue('S'.$row, $shippingList)
            ->setCellValue('T'.$row, $shippingBilled);
        $sheet->getStyle('R'.$row.':T'.$row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('R'.$row, 'TOTAL')
            ->setCellValue('S'.$row, $subtotalList + $shippingList)
            ->setCellValue('T'.$row, $subtotalBilled + $shippingBilled);
        $sheet->getStyle('R'.$row.':T'.$row)->getFont()->setBold(true);

        return $row + 4;
    }

    /**
     * @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    private function filterPayload(array $prepared): array
    {
        return [
            'country' => $prepared['countryId'],
            'countryName' => $prepared['countryName'],
            'store' => $prepared['store']['code'] ?? 'TODO',
            'storeId' => $prepared['store']['id'] ?? null,
            'storeName' => $prepared['storeName'],
            'paymentType' => $prepared['paymentType'],
            'status' => $prepared['status'],
            'startDate' => $prepared['startDate'],
            'endDate' => $prepared['endDate'],
        ];
    }

    private function resolveCountryId(string $country): int
    {
        $country = trim($country);
        $query = DB::table('stj_paises')->select(['pai_id']);
        $resolved = is_numeric($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $resolved) {
            throw ValidationException::withMessages(['country' => 'El pais seleccionado no existe.']);
        }

        return (int) $resolved->pai_id;
    }

    private function countryName(int $countryId): string
    {
        return (string) (DB::table('stj_paises')->where('pai_id', $countryId)->value('pai_nombre') ?? $countryId);
    }

    /**
     * @return array{code: string, id: ?int, name: string}
     */
    private function resolveStore(int $countryId, mixed $store): array
    {
        $store = trim((string) $store);

        if ($store === '' || $store === '0' || strtoupper($store) === 'TODO') {
            return [
                'code' => strtoupper($store) === 'TODO' ? 'TODO' : '0',
                'id' => null,
                'name' => strtoupper($store) === 'TODO' ? 'TODO' : 'Todas',
            ];
        }

        $query = DB::table('stj_tiendas')->select(['tie_id', 'tie_codigo', 'tie_nombre'])->where('tie_pais', $countryId);
        $resolved = (clone $query)->where('tie_codigo', $store)->first();

        if (! $resolved && is_numeric($store)) {
            $resolved = (clone $query)->where('tie_id', (int) $store)->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages(['store' => 'La tienda seleccionada no existe para el pais indicado.']);
        }

        return [
            'code' => (string) $resolved->tie_codigo,
            'id' => (int) $resolved->tie_id,
            'name' => trim((string) $resolved->tie_nombre),
        ];
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function spanishDate(string $value): string
    {
        return Carbon::parse($value)->locale('es')->translatedFormat('l d F Y');
    }

    private function spanishDateTime(Carbon $value): string
    {
        return $value->locale('es')->translatedFormat('l d F Y - h:i:s A');
    }

    private function dateRangeLabel(string $start, string $end): string
    {
        return $start === $end
            ? $this->spanishDate($start)
            : $this->spanishDate($start).' al '.$this->spanishDate($end);
    }

    private function refundAmount(object $row): float
    {
        if ((string) ($row->ped_devolucion_realizada ?? '') === 'N/A') {
            return 0.0;
        }

        $refund = (float) ($row->ped_monto_devolucion ?? 0);

        return match ((string) ($row->ped_estatus ?? '')) {
            'ANULADO-CLIENTE', 'ANULADO-INVENTARIO' => $refund - ((float) ($row->ppa_monto ?? 0) - (float) ($row->ppa_monto_senv ?? 0)),
            default => $refund,
        };
    }

    private function companyName(int $countryId): string
    {
        return match ($countryId) {
            1 => 'Tiendas y Franquicias S.A de C.V',
            2 => "St. Jack's de Guatemala",
            3 => "St. Jack's de Costa Rica",
            default => $this->countryName($countryId),
        };
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
