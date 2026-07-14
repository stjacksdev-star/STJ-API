<?php

namespace App\Services\Dashboard;

use App\Services\Mail\Smtp2GoMailer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderReferenceService
{
    private const CARD_AMOUNT_INCREASE_TOLERANCE = 0.05;

    public function __construct(
        private readonly Smtp2GoMailer $mailer,
    ) {
    }

    public function show(string $reference, string $country): array
    {
        $countryId = $this->resolveCountryId($country);
        $order = $this->order($reference, $countryId);

        if (! $order) {
            throw ValidationException::withMessages([
                'reference' => 'No se encontro la referencia indicada.',
            ]);
        }

        $products = $this->withLoggedChanges(
            (string) $order->ppa_ref,
            $this->products((string) $order->ppa_ref, $countryId),
        );

        return [
            'order' => $this->normalizeOrder($order, $products),
            'products' => $products,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function search(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $store = $this->resolveStore($countryId, $filters['store'] ?? null);
        $text = trim((string) ($filters['query'] ?? ''));

        if (mb_strlen($text) < 2) {
            throw ValidationException::withMessages([
                'query' => 'Escriba al menos 2 caracteres para buscar.',
            ]);
        }

        $tokens = collect(preg_split('/\s+/', $text) ?: [])
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => $token !== '')
            ->unique()
            ->take(8)
            ->values()
            ->all();
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));

        $query = DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->whereRaw('pay.ppa_id = (SELECT spp.ppa_id FROM stj_pedidos_pago spp WHERE spp.ppa_pedido = p.ped_id ORDER BY spp.ppa_id DESC LIMIT 1)');
            })
            ->leftJoin('stj_pedidos_direccion as pd', 'pd.pdi_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_direcciones as d', 'pd.pdi_direccion', '=', 'd.dir_id')
            ->leftJoin('stj_pedidos_tienda as pt', 'pt.pti_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_tiendas as order_store', function ($join) use ($countryId) {
                $join->on('order_store.tie_codigo', '=', 'pt.pti_tienda')
                    ->where('order_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_tiendas as pending_store', function ($join) use ($countryId) {
                $join->on('pending_store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('pending_store.tie_pais', '=', $countryId);
            })
            ->where('p.ped_id_pais', $countryId)
            ->when($store['code'] ?? null, function ($builder, string $storeCode) {
                $builder->where(function ($storeQuery) use ($storeCode) {
                    $storeQuery
                        ->where('pt.pti_tienda', $storeCode)
                        ->orWhere('p.ped_tienda', $storeCode);
                });
            })
            ->where(function ($builder) use ($text, $tokens) {
                $builder->where(function ($fullQuery) use ($text) {
                    $like = '%'.$text.'%';
                    $this->applyOrderSearchTerm($fullQuery, $like);
                });

                foreach ($tokens as $token) {
                    $builder->orWhere(function ($tokenQuery) use ($token) {
                        $this->applyOrderSearchTerm($tokenQuery, '%'.$token.'%');
                    });
                }
            })
            ->selectRaw("
                p.ped_id,
                p.ped_id_pais,
                p.ped_fecha,
                p.ped_checkout,
                p.ped_origen,
                p.ped_estatus,
                p.ped_estatus_productos,
                p.ped_nombres,
                p.ped_apellidos,
                p.ped_identificacion,
                p.ped_email,
                p.ped_telefono,
                p.ped_whatsapp,
                p.ped_sesion,
                p.ped_direccion,
                p.ped_ciudad,
                p.ped_estado,
                p.ped_pais,
                pay.ppa_id,
                pay.ppa_ref,
                pay.ppa_estado,
                pay.ppa_fecha,
                pay.ppa_tipo,
                pay.ppa_emisor,
                pay.ppa_tarjeta,
                pay.ppa_monto,
                pay.ppa_monto_senv,
                pay.ppa_articulos,
                pay.ppa_cambio,
                COALESCE(order_store.tie_nombre, pending_store.tie_nombre) AS tie_nombre,
                COALESCE(order_store.tie_codigo, pending_store.tie_codigo, p.ped_tienda) AS tie_codigo,
                COALESCE(order_store.tie_id, pending_store.tie_id) AS tie_id,
                CONCAT_WS(', ', d.dir_direccion, d.dir_municipio_txt, d.dir_departamento_txt, d.dir_referencia) AS direccion_envio
            ")
            ->orderByRaw('COALESCE(pay.ppa_fecha, p.ped_fecha) DESC')
            ->limit($limit);

        $rows = $query->get()
            ->map(fn ($row) => $this->normalizeSearchOrder($row))
            ->values()
            ->all();

        return [
            'filters' => [
                'country' => $countryId,
                'query' => $text,
                'store' => $store['code'] ?? null,
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? null,
                'limit' => $limit,
            ],
            'summary' => [
                'orders' => count($rows),
                'items' => array_sum(array_column($rows, 'items')),
                'amount' => array_sum(array_column($rows, 'amount')),
            ],
            'orders' => $rows,
        ];
    }

    public function lookupProduct(string $sku, string $country, ?string $size = null): array
    {
        $countryId = $this->resolveCountryId($country);
        $product = $this->resolveActiveProduct($sku, $countryId);

        if (filled($size)) {
            $this->ensureValidSize($product, (string) $size);
        }

        return $product;
    }

    public function paymentAttempts(int $orderId, string $country, mixed $store = null): array
    {
        $countryId = $this->resolveCountryId($country);
        $storeInfo = $this->resolveStore($countryId, $store);

        $order = DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_tienda as pt', 'pt.pti_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_tiendas as order_store', function ($join) use ($countryId) {
                $join->on('order_store.tie_codigo', '=', 'pt.pti_tienda')
                    ->where('order_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_tiendas as pending_store', function ($join) use ($countryId) {
                $join->on('pending_store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('pending_store.tie_pais', '=', $countryId);
            })
            ->where('p.ped_id', $orderId)
            ->where('p.ped_id_pais', $countryId)
            ->when($storeInfo['code'] ?? null, function ($builder, string $storeCode) {
                $builder->where(function ($storeQuery) use ($storeCode) {
                    $storeQuery
                        ->where('pt.pti_tienda', $storeCode)
                        ->orWhere('p.ped_tienda', $storeCode);
                });
            })
            ->selectRaw("
                p.ped_id,
                p.ped_id_pais,
                p.ped_nombres,
                p.ped_apellidos,
                p.ped_email,
                p.ped_tipo_identificacion,
                p.ped_identificacion,
                COALESCE(order_store.tie_nombre, pending_store.tie_nombre) AS tie_nombre,
                COALESCE(order_store.tie_codigo, pending_store.tie_codigo, p.ped_tienda) AS tie_codigo,
                COALESCE(order_store.tie_id, pending_store.tie_id) AS tie_id
            ")
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'order' => 'No se encontro el pedido indicado para el pais y tienda seleccionados.',
            ]);
        }

        $attempts = DB::table('stj_pedidos_pago as pay')
            ->leftJoin('stj_mensajes_fac as mf', function ($join) {
                $join->on('mf.mfa_tarjeta', '=', 'pay.ppa_emisor')
                    ->on('mf.mfa_codigo', '=', 'pay.ppa_rsp_codigo');
            })
            ->where('pay.ppa_pedido', $orderId)
            ->orderByDesc('pay.ppa_fecha')
            ->select([
                'pay.ppa_id',
                'pay.ppa_ref',
                'pay.ppa_monto',
                'pay.ppa_fecha',
                'pay.ppa_tarjeta',
                'pay.ppa_emisor',
                'pay.ppa_estado',
                'pay.ppa_autorizacion',
                'pay.ppa_rsp_codigo',
                'pay.ppa_rsp_mensaje',
                'pay.ppa_detalle',
                'mf.mfa_mensaje',
            ])
            ->get()
            ->map(fn ($attempt, int $index) => [
                'number' => $index + 1,
                'id' => (int) $attempt->ppa_id,
                'reference' => (string) ($attempt->ppa_ref ?? ''),
                'amount' => (float) ($attempt->ppa_monto ?? 0),
                'date' => (string) ($attempt->ppa_fecha ?? ''),
                'card' => (string) ($attempt->ppa_tarjeta ?? ''),
                'issuer' => (string) ($attempt->ppa_emisor ?? ''),
                'status' => (string) ($attempt->ppa_estado ?? ''),
                'authorization' => (string) ($attempt->ppa_autorizacion ?? ''),
                'code' => (string) ($attempt->ppa_rsp_codigo ?? ''),
                'message' => (string) ($attempt->mfa_mensaje ?? $attempt->ppa_rsp_mensaje ?? ''),
                'bankResponse' => $this->paymentAttemptDetail($attempt->ppa_detalle ?? null),
            ])
            ->values()
            ->all();

        return [
            'order' => [
                'id' => (int) $order->ped_id,
                'countryId' => (int) $order->ped_id_pais,
                'customer' => trim((string) ($order->ped_nombres ?? '').' '.(string) ($order->ped_apellidos ?? '')),
                'email' => (string) ($order->ped_email ?? ''),
                'identificationType' => (string) ($order->ped_tipo_identificacion ?? 'Identificacion'),
                'identification' => (string) ($order->ped_identificacion ?? ''),
                'storeCode' => (string) ($order->tie_codigo ?? ''),
                'storeId' => $order->tie_id !== null ? (int) $order->tie_id : null,
                'storeName' => (string) ($order->tie_nombre ?? ''),
            ],
            'summary' => [
                'attempts' => count($attempts),
            ],
            'attempts' => $attempts,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function refunds(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $store = $this->resolveStore($countryId, $filters['store'] ?? null);
        $status = $this->refundStatus($filters['status'] ?? null);
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

        $query = DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_tipo', '=', 'TARJETA')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->leftJoin('stj_pedidos_tienda as pt', 'pt.pti_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_tiendas as order_store', function ($join) use ($countryId) {
                $join->on('order_store.tie_codigo', '=', 'pt.pti_tienda')
                    ->where('order_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_tiendas as pending_store', function ($join) use ($countryId) {
                $join->on('pending_store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('pending_store.tie_pais', '=', $countryId);
            })
            ->where('p.ped_id_pais', $countryId)
            ->whereIn('p.ped_devolucion_realizada', ['SI', 'NO'])
            ->when($status, fn ($builder, string $value) => $builder->where('p.ped_devolucion_realizada', $value))
            ->when($store['code'] ?? null, function ($builder, string $storeCode) {
                $builder->where(function ($storeQuery) use ($storeCode) {
                    $storeQuery
                        ->where('pt.pti_tienda', $storeCode)
                        ->orWhere('p.ped_tienda', $storeCode);
                });
            })
            ->when($start !== null && $end !== null, function ($builder) use ($start, $end) {
                $builder->whereRaw('DATE(COALESCE(p.ped_fecha_devolucion, pay.ppa_fecha)) BETWEEN ? AND ?', [$start, $end]);
            })
            ->selectRaw("
                p.ped_id,
                p.ped_id_pais,
                p.ped_checkout,
                p.ped_origen,
                p.ped_estatus,
                p.ped_devolucion_realizada,
                p.ped_rsp_servicio,
                p.ped_monto_devolucion,
                p.ped_fecha_devolucion,
                p.ped_observacion_devolucion,
                p.ped_nombres,
                p.ped_apellidos,
                p.ped_identificacion,
                p.ped_email,
                p.ped_telefono,
                p.ped_whatsapp,
                pay.ppa_ref,
                pay.ppa_fecha,
                pay.ppa_tipo,
                pay.ppa_monto,
                pay.ppa_monto_senv,
                pay.ppa_articulos,
                COALESCE(order_store.tie_nombre, pending_store.tie_nombre) AS tie_nombre,
                COALESCE(order_store.tie_codigo, pending_store.tie_codigo, p.ped_tienda) AS tie_codigo,
                COALESCE(order_store.tie_id, pending_store.tie_id) AS tie_id
            ")
            ->orderByRaw('COALESCE(p.ped_fecha_devolucion, pay.ppa_fecha) DESC');

        $rows = $query->get()
            ->map(fn ($row) => $this->normalizeRefund($row))
            ->values()
            ->all();

        return [
            'filters' => [
                'country' => $countryId,
                'startDate' => $start,
                'endDate' => $end,
                'status' => $status,
                'store' => $store['code'] ?? null,
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? null,
            ],
            'summary' => [
                'orders' => count($rows),
                'pending' => count(array_filter($rows, fn (array $row) => $row['refundStatus'] === 'NO')),
                'processed' => count(array_filter($rows, fn (array $row) => $row['refundStatus'] === 'SI')),
                'amount' => array_sum(array_column($rows, 'refundAmount')),
            ],
            'refunds' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateData(string $reference, string $country, array $data, array $actor = []): array
    {
        return DB::transaction(function () use ($reference, $country, $data, $actor) {
            $countryId = $this->resolveCountryId($country);
            $order = $this->order($reference, $countryId);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro la referencia indicada.',
                ]);
            }

            if ((string) $order->ped_estatus !== 'RECIBIDO') {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden editar datos de pedidos en estado RECIBIDO.',
                ]);
            }

            $actorName = $this->actorName($actor);
            $actorIp = (string) ($actor['ip'] ?? Request::ip());

            DB::table('stj_pedidos')
                ->where('ped_id', (int) $order->ped_id)
                ->where('ped_id_pais', $countryId)
                ->update([
                    'ped_email' => $this->trimText($data['email'] ?? '', 50),
                    'ped_telefono' => $this->trimText($data['phone'] ?? '', 30),
                    'ped_whatsapp' => $this->trimText($data['whatsapp'] ?? '', 30),
                    'ped_direccion' => $this->trimText($data['billingAddress'] ?? '', 200),
                    'ped_a_usuario' => $actorName,
                    'ped_a_ip' => $actorIp,
                ]);

            if ($order->dir_id !== null && array_key_exists('shippingAddress', $data)) {
                DB::table('stj_direcciones')
                    ->where('dir_id', (int) $order->dir_id)
                    ->update([
                        'dir_direccion' => $this->trimText($data['shippingAddress'] ?? '', 200),
                        'dir_referencia' => $this->trimText($data['shippingReference'] ?? '', 200),
                        'dir_a_usuario' => $actorName,
                        'dir_a_ip' => $actorIp,
                    ]);
            }

            return $this->show($reference, (string) $countryId);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function shippingManagement(string $reference, array $actor = []): array
    {
        $this->ensureRootActor($actor);

        $order = $this->shippingManagementOrder($reference);

        if (! $order) {
            throw ValidationException::withMessages([
                'reference' => 'No se encontro un pedido con la referencia STJ indicada.',
            ]);
        }

        return $this->normalizeShippingManagement($order);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     */
    public function updateShippingManagement(string $reference, array $data, array $actor = []): array
    {
        $this->ensureRootActor($actor);

        return DB::transaction(function () use ($reference, $data, $actor) {
            $order = $this->shippingManagementOrder($reference, true);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro un pedido con la referencia STJ indicada.',
                ]);
            }

            if (strtoupper((string) $order->ped_checkout) !== 'DOMICILIO' || $order->pdi_id === null || $order->dir_id === null) {
                throw ValidationException::withMessages([
                    'order' => 'El pedido no es de tipo DOMICILIO o no tiene una direccion asociada.',
                ]);
            }

            $actorName = $this->actorName($actor);
            $actorIp = $actor['ip'] ?? Request::ip();
            $now = now();

            DB::table('stj_pedidos_direccion')
                ->where('pdi_id', (int) $order->pdi_id)
                ->update([
                    'pdi_tipo_envio' => $this->trimText($data['shippingType'] ?? '', 50),
                    'pdi_id_urbano' => $this->trimText($data['urbanId'] ?? '', 100),
                    'pdi_id_shipping' => $this->nullableTrimText($data['shippingId'] ?? null, 100),
                    'pdi_costo_envio' => (float) ($data['shippingCost'] ?? 0),
                    'pdi_costo_envio_txt' => $this->trimText($data['shippingCostText'] ?? '', 200),
                    'pdi_costo_envio_final' => (float) ($data['finalShippingCost'] ?? 0),
                    'pdi_aplica_envio_gratis' => strtoupper((string) ($data['freeShipping'] ?? 'NO')),
                    'pdi_fecha_ruta' => $this->nullableDateTime($data['routeAt'] ?? null),
                    'pdi_a_usuario' => $actorName,
                    'pdi_a_ip' => $actorIp,
                    'pdi_a_fecha' => $now,
                ]);

            DB::table('stj_direcciones')
                ->where('dir_id', (int) $order->dir_id)
                ->update([
                    'dir_tipo' => $this->trimText($data['addressType'] ?? '', 30),
                    'dir_misma_persona' => strtoupper((string) ($data['samePerson'] ?? 'NO')),
                    'dir_misma_direccion' => strtoupper((string) ($data['sameAddress'] ?? 'NO')),
                    'dir_pais' => $this->trimText($data['country'] ?? '', 10),
                    'dir_latitud' => $this->nullableTrimText($data['latitude'] ?? null, 50),
                    'dir_longitud' => $this->nullableTrimText($data['longitude'] ?? null, 50),
                    'dir_direccion' => $this->trimText($data['address'] ?? '', 200),
                    'dir_referencia' => $this->trimText($data['referencePoint'] ?? '', 200),
                    'dir_departamento' => $this->nullableTrimText($data['departmentId'] ?? null, 30),
                    'dir_municipio' => $this->nullableTrimText($data['municipalityId'] ?? null, 30),
                    'dir_departamento_txt' => $this->trimText($data['department'] ?? '', 100),
                    'dir_municipio_txt' => $this->trimText($data['municipality'] ?? '', 100),
                    'dir_distrito' => $this->nullableTrimText($data['district'] ?? null, 100),
                    'dir_persona' => $this->trimText($data['receiverName'] ?? '', 100),
                    'dir_telefono' => $this->trimText($data['receiverPhone'] ?? '', 100),
                    'dir_save' => $this->trimText($data['saveType'] ?? '', 20),
                    'dir_a_usuario' => $actorName,
                    'dir_a_ip' => $actorIp,
                    'dir_a_fecha' => $now,
                ]);

            $updated = $this->shippingManagementOrder($reference);

            return $this->normalizeShippingManagement($updated);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLine(int $lineId, array $data, array $actor = []): array
    {
        return DB::transaction(function () use ($lineId, $data, $actor) {
            $line = $this->editableLine($lineId);

            if (! $line) {
                throw ValidationException::withMessages([
                    'line' => 'La linea seleccionada no existe.',
                ]);
            }

            if (! in_array((string) $line->ped_estatus, ['RECIBIDO', 'EMPACADO-ENTREGA'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden editar articulos de pedidos en estado RECIBIDO o EMPACADO-ENTREGA.',
                ]);
            }

            $countryId = (int) $line->ped_id_pais;
            $product = $this->resolveActiveProduct((string) $data['sku'], $countryId);
            $this->ensureValidSize($product, (string) $data['size']);

            $quantity = max(0, (int) $data['quantity']);
            $discount = max(0, min(100, (float) ($data['discount'] ?? 0)));
            $actorName = $this->actorName($actor);
            $quantityChanged = $quantity !== (int) ($line->car_cantidad ?? 0);
            $hasOriginalQuantityCopy = (int) ($line->car_cantidad_copia ?? 0) > 0;

            $this->ensureCardLineDoesNotIncreasePayment($line, $product['price'], $quantity, $discount);

            $updates = [
                'car_producto' => $product['id'],
                'car_precio' => $product['price'],
                'car_talla' => trim((string) $data['size']),
                'car_cantidad' => $quantity,
                'car_descuento' => $discount,
                'car_total_facturado' => null,
                'car_descuento_final' => null,
                'car_estilo_final' => null,
                'car_talla_final' => null,
                'car_modificar' => 'SI',
                'car_a_usuario' => $actorName,
                'car_a_ip' => $actor['ip'] ?? Request::ip(),
                'car_a_fecha' => now(),
            ];

            if ($quantityChanged && ! $hasOriginalQuantityCopy) {
                $updates['car_cantidad_copia'] = $this->originalQuantityForLine($lineId, (int) ($line->car_cantidad ?? 0));
            }

            DB::table('stj_pedidos_detalle')
                ->where('car_id', $lineId)
                ->update($updates);

            $updatedLine = $this->editableLine($lineId);

            if ($updatedLine) {
                $this->logLineChange($line, $updatedLine, $actor);
            }

            return $this->show((string) $line->car_ref, (string) $countryId);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function processOrder(string $reference, string $country, string $ticket, ?string $refundObservation = null, array $actor = []): array
    {
        $processed = DB::transaction(function () use ($reference, $country, $ticket, $refundObservation, $actor) {
            $countryId = $this->resolveCountryId($country);
            $order = $this->orderForProcessing($reference, $countryId);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro la referencia indicada.',
                ]);
            }

            if (! in_array((string) $order->ped_estatus, ['RECIBIDO', 'EMPACADO-ENTREGA'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden procesar pedidos en estado RECIBIDO o EMPACADO-ENTREGA.',
                ]);
            }

            $products = $this->products((string) $order->ppa_ref, $countryId);

            if ($products === []) {
                throw ValidationException::withMessages([
                    'products' => 'El pedido no tiene articulos para procesar.',
                ]);
            }

            $now = now();
            $actorName = $this->actorName($actor);
            $shipping = (string) ($order->ped_checkout ?? '') === 'DOMICILIO'
                ? (float) ($order->pdi_costo_envio_final ?? 0)
                : 0.0;
            $items = collect($products)->sum(fn (array $product) => (int) ($product['quantity'] ?? 0));
            $chargedSubtotal = collect($products)->sum(fn (array $product) => (float) ($product['chargedSubtotal'] ?? 0));
            $orderIsCancelledByInventory = $items === 0;
            $calculatedPaid = $orderIsCancelledByInventory ? 0.0 : round($chargedSubtotal + $shipping, 2);
            $originalPaid = round((float) ($order->ppa_monto ?? 0), 2);
            $difference = round($calculatedPaid - $originalPaid, 2);
            $isCardPayment = strtoupper((string) ($order->ppa_tipo ?? '')) === 'TARJETA';

            if ($isCardPayment && $difference > self::CARD_AMOUNT_INCREASE_TOLERANCE) {
                throw ValidationException::withMessages([
                    'order' => 'No se puede procesar un pedido pagado con tarjeta cuando el detalle supera el total aprobado.',
                ]);
            }

            $refund = $isCardPayment && $difference < 0 ? abs($difference) : 0.0;
            $refundObservation = trim((string) $refundObservation);

            if ($refund > 0 && mb_strlen($refundObservation) < 20) {
                throw ValidationException::withMessages([
                    'refundObservation' => 'Debe ingresar una observacion de devolucion de al menos 20 caracteres.',
                ]);
            }

            $originalItems = (int) ($order->ppa_articulos ?? 0);
            $originalProducts = round((float) ($order->ppa_monto_senv ?? 0), 2);
            $hasLineChanges = $this->hasLineChanges((string) $order->ppa_ref);
            $orderStatus = $orderIsCancelledByInventory ? 'ANULADO-INVENTARIO' : 'PREPARADO';
            $productStatus = $orderIsCancelledByInventory
                ? 'SIN-EXISTENCIAS'
                : (($items !== $originalItems || $hasLineChanges || abs(round($chargedSubtotal - $originalProducts, 2)) >= 0.01)
                    ? 'INCOMPLETO'
                    : 'COMPLETO');

            DB::table('stj_pedidos')
                ->where('ped_id', (int) $order->ped_id)
                ->update([
                    'ped_estatus' => $orderStatus,
                    'ped_estatus_productos' => $productStatus,
                    'ped_devolucion_realizada' => $refund > 0 ? 'NO' : 'N/A',
                    'ped_monto_devolucion' => $refund > 0 ? $refund : null,
                    'ped_fecha_devolucion' => $refund > 0 ? $now : null,
                    'ped_fecha_devolucion_sistema' => $refund > 0 ? $now : null,
                    'ped_observacion_devolucion' => $refund > 0 ? $refundObservation : null,
                    'ped_a_usuario' => $actorName,
                    'ped_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ped_a_fecha' => $now,
                ]);

            DB::table('stj_pedidos_pago')
                ->where('ppa_id', (int) $order->ppa_id)
                ->update([
                    'ppa_ticket' => trim($ticket),
                    'ppa_fecha_procesado' => $now,
                    'ppa_a_usuario' => $actorName,
                    'ppa_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ppa_a_fecha' => $now,
                ]);

            $details = DB::table('stj_pedidos_detalle as detail')
                ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
                ->where('detail.car_ref', (string) $order->ppa_ref)
                ->where('detail.car_pais', $countryId)
                ->where('detail.car_accion', 'AGREGADO')
                ->select([
                    'detail.car_id',
                    'detail.car_cantidad',
                    'detail.car_descuento',
                    'detail.car_talla',
                    'product.pro_codigo',
                ])
                ->get();

            foreach ($details as $detail) {
                DB::table('stj_pedidos_detalle')
                    ->where('car_id', (int) $detail->car_id)
                    ->update([
                        'car_total_facturado' => (int) $detail->car_cantidad,
                        'car_descuento_final' => (float) ($detail->car_descuento ?? 0),
                        'car_estilo_final' => (string) $detail->pro_codigo,
                        'car_talla_final' => (string) ($detail->car_talla ?? ''),
                        'car_a_usuario' => $actorName,
                        'car_a_ip' => $actor['ip'] ?? Request::ip(),
                        'car_a_fecha' => $now,
                    ]);
            }

            return $this->show((string) $order->ppa_ref, (string) $countryId);
        });

        $processed['mail'] = $this->sendProcessedOrderEmail($processed);

        return $processed;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function deliverOrder(string $reference, string $country, array $actor = []): array
    {
        $delivered = DB::transaction(function () use ($reference, $country, $actor) {
            $countryId = $this->resolveCountryId($country);
            $order = $this->orderForDelivery($reference, $countryId);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro la referencia indicada.',
                ]);
            }

            $canDeliverStore = (string) $order->ped_checkout === 'TIENDA' && (string) $order->ped_estatus === 'PREPARADO';
            $canDeliverHome = (string) $order->ped_checkout === 'DOMICILIO' && (string) $order->ped_estatus === 'EN-RUTA';

            if (! $canDeliverStore && ! $canDeliverHome) {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden entregar pedidos de tienda en estado PREPARADO o pedidos a domicilio en estado EN-RUTA.',
                ]);
            }

            if ($canDeliverStore) {
                $this->ensureActorCanDeliverStore($order, $actor);
            } else {
                $this->ensureActorCountry($order, $actor);
            }

            $now = now();
            $actorName = $this->actorName($actor);

            DB::table('stj_pedidos_pago')
                ->where('ppa_id', (int) $order->ppa_id)
                ->update([
                    'ppa_pagado' => 'SI',
                    'ppa_fecha_entregado' => $now,
                    'ppa_a_usuario' => $actorName,
                    'ppa_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ppa_a_fecha' => $now,
                ]);

            DB::table('stj_pedidos')
                ->where('ped_id', (int) $order->ped_id)
                ->update([
                    'ped_estatus' => 'ENTREGADO',
                    'ped_a_usuario' => $actorName,
                    'ped_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ped_a_fecha' => $now,
                ]);

            return $this->show((string) $order->ppa_ref, (string) $countryId);
        });

        $delivered['mail'] = $this->sendDeliveredOrderEmail($delivered);

        return $delivered;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function markOrderPackedForPickup(string $reference, string $country, array $actor = []): array
    {
        $packed = DB::transaction(function () use ($reference, $country, $actor) {
            $countryId = $this->resolveCountryId($country);
            $order = $this->orderForPackedPickup($reference, $countryId);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro la referencia indicada.',
                ]);
            }

            if ((string) $order->ped_estatus !== 'RECIBIDO') {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden marcar como preparados pedidos en estado RECIBIDO.',
                ]);
            }

            if (strtoupper((string) ($order->ppa_tipo ?? '')) !== 'EFECTIVO') {
                throw ValidationException::withMessages([
                    'payment' => 'Solo se pueden marcar como preparados pedidos con pago en efectivo.',
                ]);
            }

            $this->ensureActorCountry($order, $actor);

            $now = now();
            $actorName = $this->actorName($actor);

            DB::table('stj_pedidos')
                ->where('ped_id', (int) $order->ped_id)
                ->update([
                    'ped_estatus' => 'EMPACADO-ENTREGA',
                    'ped_a_usuario' => $actorName,
                    'ped_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ped_a_fecha' => $now,
                ]);

            return $this->show((string) $order->ppa_ref, (string) $countryId);
        });

        $packed['mail'] = $this->sendPackedForPickupEmail($packed);

        return $packed;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function markOrderInRoute(string $reference, string $country, array $actor = []): array
    {
        $routed = DB::transaction(function () use ($reference, $country, $actor) {
            $countryId = $this->resolveCountryId($country);
            $order = $this->orderForRouting($reference, $countryId);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro la referencia indicada.',
                ]);
            }

            if ((string) $order->ped_checkout !== 'DOMICILIO' || (string) $order->ped_estatus !== 'PREPARADO') {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden marcar en ruta pedidos a domicilio en estado PREPARADO.',
                ]);
            }

            $this->ensureActorCountry($order, $actor);

            $now = now();
            $actorName = $this->actorName($actor);

            DB::table('stj_pedidos')
                ->where('ped_id', (int) $order->ped_id)
                ->update([
                    'ped_estatus' => 'EN-RUTA',
                    'ped_a_usuario' => $actorName,
                    'ped_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ped_a_fecha' => $now,
                ]);

            DB::table('stj_pedidos_direccion')
                ->where('pdi_pedido', (int) $order->ped_id)
                ->update([
                    'pdi_fecha_ruta' => $now,
                    'pdi_a_usuario' => $actorName,
                    'pdi_a_ip' => $actor['ip'] ?? Request::ip(),
                    'pdi_a_fecha' => $now,
                ]);

            return $this->show((string) $order->ppa_ref, (string) $countryId);
        });

        $routed['mail'] = $this->sendInRouteOrderEmail($routed);

        return $routed;
    }

    private function editableLine(int $lineId): ?object
    {
        return DB::table('stj_pedidos_detalle as detail')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_ref', '=', 'detail.car_ref')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->join('stj_pedidos as order', 'order.ped_id', '=', 'pay.ppa_pedido')
            ->leftJoin('stj_pedidos_direccion as shipping', 'shipping.pdi_pedido', '=', 'order.ped_id')
            ->leftJoin('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->selectRaw('
                detail.*,
                pay.ppa_id,
                pay.ppa_ref,
                pay.ppa_tipo,
                pay.ppa_monto,
                order.ped_id,
                order.ped_id_pais,
                order.ped_estatus,
                order.ped_checkout,
                shipping.pdi_costo_envio_final,
                product.pro_codigo,
                product.pro_nombre
            ')
            ->where('detail.car_id', $lineId)
            ->where('detail.car_accion', 'AGREGADO')
            ->lockForUpdate()
            ->first();
    }

    private function ensureCardLineDoesNotIncreasePayment(object $line, float $price, int $quantity, float $discount): void
    {
        if (strtoupper((string) ($line->ppa_tipo ?? '')) !== 'TARJETA') {
            return;
        }

        $products = $this->products((string) $line->ppa_ref, (int) $line->ped_id_pais);
        $currentSubtotal = collect($products)
            ->sum(fn (array $product) => (float) ($product['chargedSubtotal'] ?? 0));
        $editedLineSubtotal = collect($products)
            ->first(fn (array $product) => (int) ($product['id'] ?? 0) === (int) $line->car_id)['chargedSubtotal'] ?? 0;
        $newLineSubtotal = $quantity * ($price * (1 - ($discount / 100)));
        $shipping = (string) ($line->ped_checkout ?? '') === 'DOMICILIO'
            ? (float) ($line->pdi_costo_envio_final ?? 0)
            : 0.0;
        $newCalculatedPaid = round($currentSubtotal - (float) $editedLineSubtotal + $newLineSubtotal + $shipping, 2);
        $approvedPaid = round((float) ($line->ppa_monto ?? 0), 2);
        $increase = round($newCalculatedPaid - $approvedPaid, 2);

        if ($increase > self::CARD_AMOUNT_INCREASE_TOLERANCE) {
            throw ValidationException::withMessages([
                'line' => 'No se puede aumentar el monto de un pedido pagado con tarjeta por mas de 0.05 sobre el total aprobado.',
            ]);
        }
    }

    private function orderForProcessing(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_direccion as pd', 'p.ped_id', '=', 'pd.pdi_pedido')
            ->join('stj_pedidos_pago as pay', function ($join) use ($reference) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_ref', '=', $reference)
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->where('p.ped_id_pais', $countryId)
            ->select([
                'p.ped_id',
                'p.ped_id_pais',
                'p.ped_estatus',
                'p.ped_checkout',
                'pd.pdi_costo_envio_final',
                'pay.ppa_id',
                'pay.ppa_ref',
                'pay.ppa_monto',
                'pay.ppa_monto_senv',
                'pay.ppa_articulos',
                'pay.ppa_tipo',
            ])
            ->lockForUpdate()
            ->first();
    }

    private function orderForRouting(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) use ($reference) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_ref', '=', $reference)
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->leftJoin('stj_pedidos_direccion as pd', 'pd.pdi_pedido', '=', 'p.ped_id')
            ->where('p.ped_id_pais', $countryId)
            ->select([
                'p.ped_id',
                'p.ped_id_pais',
                'p.ped_estatus',
                'p.ped_checkout',
                'pay.ppa_ref',
                'pd.pdi_id',
            ])
            ->lockForUpdate()
            ->first();
    }

    private function orderForDelivery(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_tienda as pt', 'p.ped_id', '=', 'pt.pti_pedido')
            ->leftJoin('stj_tiendas as pickup_store', function ($join) use ($countryId) {
                $join->on('pickup_store.tie_codigo', '=', 'pt.pti_tienda')
                    ->where('pickup_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_tiendas as order_store', function ($join) use ($countryId) {
                $join->on('order_store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('order_store.tie_pais', '=', $countryId);
            })
            ->join('stj_pedidos_pago as pay', function ($join) use ($reference) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_ref', '=', $reference)
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->where('p.ped_id_pais', $countryId)
            ->selectRaw('
                p.ped_id,
                p.ped_id_pais,
                p.ped_estatus,
                p.ped_checkout,
                p.ped_tienda,
                pay.ppa_id,
                pay.ppa_ref,
                COALESCE(pickup_store.tie_codigo, order_store.tie_codigo, pt.pti_tienda, p.ped_tienda) AS store_code,
                COALESCE(pickup_store.tie_nombre, order_store.tie_nombre) AS store_name
            ')
            ->lockForUpdate()
            ->first();
    }

    private function orderForPackedPickup(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', function ($join) use ($reference) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_ref', '=', $reference)
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->where('p.ped_id_pais', $countryId)
            ->select([
                'p.ped_id',
                'p.ped_id_pais',
                'p.ped_estatus',
                'pay.ppa_ref',
                'pay.ppa_tipo',
            ])
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function ensureActorCanDeliverStore(object $order, array $actor): void
    {
        $this->ensureActorCountry($order, $actor);

        $actorStore = $this->normalizeStoreCode($actor['storeCode'] ?? '');

        if ($actorStore === '' && filled($actor['storeId'] ?? null)) {
            $actorStore = $this->storeCodeById((int) $actor['storeId'], (int) $order->ped_id_pais);
        }

        if ($actorStore === '' || $actorStore === '0') {
            return;
        }

        $orderStore = $this->normalizeStoreCode($order->store_code ?? $order->ped_tienda ?? '');

        if ($orderStore === '' || $actorStore !== $orderStore) {
            throw ValidationException::withMessages([
                'store' => 'El pedido no corresponde a la tienda de la sesion.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function ensureActorCountry(object $order, array $actor): void
    {
        $actorCountry = trim((string) ($actor['countryId'] ?? ''));

        if ($actorCountry !== '' && (int) $actorCountry !== (int) $order->ped_id_pais) {
            throw ValidationException::withMessages([
                'country' => 'El pedido no pertenece al pais de la sesion.',
            ]);
        }
    }

    private function storeCodeById(int $storeId, int $countryId): string
    {
        if ($storeId <= 0) {
            return '';
        }

        $store = DB::table('stj_tiendas')
            ->where('tie_id', $storeId)
            ->where('tie_pais', $countryId)
            ->value('tie_codigo');

        return $this->normalizeStoreCode($store);
    }

    private function normalizeStoreCode(mixed $value): string
    {
        $code = trim((string) $value);

        if ($code === '') {
            return '';
        }

        $normalized = ltrim($code, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $processed
     * @return array{sent: bool, skipped: bool, reason: string|null}
     */
    private function sendProcessedOrderEmail(array $processed): array
    {
        $order = $processed['order'];
        $email = trim((string) data_get($order, 'customer.email'));

        if ($email === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Pedido sin correo de cliente.',
            ];
        }

        if ($this->isBouncedEmail($email)) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Correo en lista de rebotes.',
            ];
        }

        try {
            $message = $this->processedOrderMail($processed);

            $this->mailer->sendHtml(
                to: $email,
                subject: $message['subject'],
                html: $message['html'],
            );

            return [
                'sent' => true,
                'skipped' => false,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('No fue posible enviar correo de pedido procesado.', [
                'reference' => data_get($order, 'reference'),
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'skipped' => false,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $delivered
     * @return array{sent: bool, skipped: bool, reason: string|null}
     */
    private function sendDeliveredOrderEmail(array $delivered): array
    {
        $order = $delivered['order'];
        $email = trim((string) data_get($order, 'customer.email'));

        if ($email === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Pedido sin correo de cliente.',
            ];
        }

        if ($this->isBouncedEmail($email)) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Correo en lista de rebotes.',
            ];
        }

        try {
            $message = $this->deliveredOrderMail($delivered);

            $this->mailer->sendHtml(
                to: $email,
                subject: $message['subject'],
                html: $message['html'],
            );

            return [
                'sent' => true,
                'skipped' => false,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('No fue posible enviar correo de pedido entregado.', [
                'reference' => data_get($order, 'reference'),
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'skipped' => false,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $packed
     * @return array{sent: bool, skipped: bool, reason: string|null}
     */
    private function sendPackedForPickupEmail(array $packed): array
    {
        $order = $packed['order'];
        $email = trim((string) data_get($order, 'customer.email'));

        if ($email === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Pedido sin correo de cliente.',
            ];
        }

        if ($this->isBouncedEmail($email)) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Correo en lista de rebotes.',
            ];
        }

        try {
            $message = $this->packedForPickupMail($packed);

            $this->mailer->sendHtml(
                to: $email,
                subject: $message['subject'],
                html: $message['html'],
            );

            return [
                'sent' => true,
                'skipped' => false,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('No fue posible enviar correo de pedido empacado para entrega.', [
                'reference' => data_get($order, 'reference'),
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'skipped' => false,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $routed
     * @return array{sent: bool, skipped: bool, reason: string|null}
     */
    private function sendInRouteOrderEmail(array $routed): array
    {
        $order = $routed['order'];
        $email = trim((string) data_get($order, 'customer.email'));

        if ($email === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Pedido sin correo de cliente.',
            ];
        }

        if ($this->isBouncedEmail($email)) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Correo en lista de rebotes.',
            ];
        }

        try {
            $message = $this->processedOrderMail($routed);

            $this->mailer->sendHtml(
                to: $email,
                subject: $message['subject'],
                html: $message['html'],
            );

            return [
                'sent' => true,
                'skipped' => false,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('No fue posible enviar correo de pedido en ruta.', [
                'reference' => data_get($order, 'reference'),
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'skipped' => false,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    private function isBouncedEmail(string $email): bool
    {
        return DB::table('correos_rebotados')
            ->where('correo', $email)
            ->exists();
    }

    private function hasLineChanges(string $reference): bool
    {
        return DB::table('stj_pedidos_detalle_log')
            ->where('pdl_ref', $reference)
            ->exists();
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function withLoggedChanges(string $reference, array $products): array
    {
        $logs = DB::table('stj_pedidos_detalle_log')
            ->where('pdl_ref', $reference)
            ->orderBy('pdl_id')
            ->get()
            ->groupBy('pdl_detalle_id');

        if ($logs->isEmpty()) {
            return $products;
        }

        return collect($products)
            ->map(function (array $product) use ($logs): array {
                $lineLogs = $logs->get($product['id']);

                if (! $lineLogs || $lineLogs->isEmpty()) {
                    return $product;
                }

                $first = $lineLogs->first();
                $last = $lineLogs->last();
                $productChanged = (string) ($first->pdl_codigo_anterior ?? '') !== (string) ($last->pdl_codigo_nuevo ?? '')
                    || (string) ($first->pdl_talla_anterior ?? '') !== (string) ($last->pdl_talla_nueva ?? '');
                $quantityChanged = (int) ($first->pdl_cantidad_anterior ?? 0) !== (int) ($last->pdl_cantidad_nueva ?? 0);

                $product['loggedChange'] = [
                    'changed' => $productChanged || $quantityChanged,
                    'productChanged' => $productChanged,
                    'quantityChanged' => $quantityChanged,
                    'sku' => (string) ($first->pdl_codigo_anterior ?? ''),
                    'name' => (string) ($first->pdl_nombre_anterior ?? ''),
                    'size' => (string) ($first->pdl_talla_anterior ?? ''),
                    'quantity' => (int) ($first->pdl_cantidad_anterior ?? 0),
                    'newSku' => (string) ($last->pdl_codigo_nuevo ?? ''),
                    'newName' => (string) ($last->pdl_nombre_nuevo ?? ''),
                    'newSize' => (string) ($last->pdl_talla_nueva ?? ''),
                    'newQuantity' => (int) ($last->pdl_cantidad_nueva ?? 0),
                ];

                if ($quantityChanged && (int) ($product['originalQuantity'] ?? 0) <= 0) {
                    $product['originalQuantity'] = (int) ($first->pdl_cantidad_anterior ?? 0);
                    $product['quantityEdited'] = (int) $product['originalQuantity'] !== (int) ($product['quantity'] ?? 0);
                }

                return $product;
            })
            ->values()
            ->all();
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $processed
     * @return array{subject: string, html: string}
     */
    private function processedOrderMail(array $processed): array
    {
        $order = $processed['order'];
        $products = $this->withLoggedChanges((string) data_get($order, 'reference'), $processed['products']);
        $reference = (string) data_get($order, 'reference');
        $checkout = (string) data_get($order, 'checkout');
        $status = (string) data_get($order, 'status');
        $customer = e((string) data_get($order, 'customer.name', 'cliente'));
        $countryId = (int) data_get($order, 'countryId');
        $currency = $this->currency($countryId);
        $refund = (float) data_get($order, 'totals.refund', 0);

        if ($status === 'ANULADO-INVENTARIO') {
            $subject = "Pedido #{$reference} no disponible";
            $title = 'Pedido no disponible';
            $intro = "No podemos facturar tu pedido con numero de referencia {$reference} debido a disponibilidad de inventario.";
        } elseif ($checkout === 'DOMICILIO' && $status === 'EN-RUTA') {
            $subject = "Pedido #{$reference} en ruta";
            $title = 'Pedido en ruta';
            $intro = "Tu pedido con numero de referencia {$reference} se encuentra en ruta.";
        } elseif ($checkout === 'DOMICILIO') {
            $subject = "Pedido #{$reference} en preparacion";
            $title = 'Pedido en preparacion';
            $intro = "Tu pedido con numero de referencia {$reference} ya fue preparado y pronto estara en ruta.";
        } else {
            $subject = "Pedido #{$reference} preparado";
            $title = 'Pedido preparado';
            $intro = "Tu pedido con numero de referencia {$reference} esta listo para retirarlo.";
        }

        $deliveryRows = $checkout === 'DOMICILIO'
            ? [
                'Tipo de entrega' => 'Domicilio',
                'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
                'Costo de envio' => $currency.' '.number_format((float) data_get($order, 'shipping.cost', 0), 2),
                'Direccion' => (string) data_get($order, 'shipping.address', ''),
            ]
            : [
                'Tipo de entrega' => trim('Retiro en tienda '.(string) data_get($order, 'storePickup.storeName', '')),
                'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
                'Total de productos' => (string) data_get($order, 'totals.itemsBilled', data_get($order, 'totals.items')),
            ];

        $changeNotice = $this->mailChangeNotice($order, $products, $currency, $refund);
        $tracking = $checkout === 'DOMICILIO'
            ? '<p>Puedes rastrear tu orden en <a href="https://stjacks.com/'.$this->countrySlug($countryId).'/Productos/Ordenes">stjacks.com</a> ingresando el numero de referencia.</p>'
            : '';

        $html = '<!doctype html>
            <html>
            <body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#1f2937;">
                <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 0;">
                    <tr>
                        <td align="center">
                            <table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e5e7eb;">
                                <tr>
                                    <td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;">
                                        <h1 style="margin:0;font-size:24px;color:#111827;">'.$title.'</h1>
                                        <p style="margin:12px 0 0;font-size:15px;">Hola <strong>'.$customer.'</strong>,</p>
                                        <p style="margin:8px 0 0;font-size:15px;">'.e($intro).'</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:22px 28px;">
                                        '.$this->mailKeyValueTable($deliveryRows).'
                                        '.$changeNotice.'
                                        '.$tracking.'
                                        <p style="margin-top:24px;font-size:13px;color:#6b7280;">Gracias por comprar en St. Jack\'s Online.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $packed
     * @return array{subject: string, html: string}
     */
    private function packedForPickupMail(array $packed): array
    {
        $order = $packed['order'];
        $products = $this->withLoggedChanges((string) data_get($order, 'reference'), $packed['products']);
        $reference = (string) data_get($order, 'reference');
        $customer = e((string) data_get($order, 'customer.name', 'cliente'));
        $storeName = trim((string) data_get($order, 'storePickup.storeName', ''));
        $storeCode = trim((string) data_get($order, 'storePickup.storeCode', ''));
        $store = trim($storeName.($storeCode !== '' ? " ({$storeCode})" : ''));
        $subject = "Pedido #{$reference} listo para retirar";
        $rows = [
            'Numero de referencia' => $reference,
            'Tienda de retiro' => $store !== '' ? $store : 'Tienda seleccionada',
            'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
            'Forma de pago' => (string) data_get($order, 'payment.type', 'EFECTIVO'),
        ];

        $html = '<!doctype html>
            <html>
            <body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#1f2937;">
                <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 0;">
                    <tr>
                        <td align="center">
                            <table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e5e7eb;">
                                <tr>
                                    <td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;">
                                        <h1 style="margin:0;font-size:24px;color:#111827;">Pedido listo para retirar</h1>
                                        <p style="margin:12px 0 0;font-size:15px;">Hola <strong>'.$customer.'</strong>,</p>
                                        <p style="margin:8px 0 0;font-size:15px;">Tu pedido con numero de referencia '.e($reference).' ya esta listo para retirar en la tienda especificada.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:22px 28px;">
                                        '.$this->mailKeyValueTable($rows).'
                                        '.$this->mailProductsTable($products, false).'
                                        <p style="margin-top:24px;font-size:13px;color:#6b7280;">Gracias por comprar en St. Jack\'s Online.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $delivered
     * @return array{subject: string, html: string}
     */
    private function deliveredOrderMail(array $delivered): array
    {
        $order = $delivered['order'];
        $reference = (string) data_get($order, 'reference');
        $customer = e((string) data_get($order, 'customer.name', 'cliente'));
        $currency = $this->currency((int) data_get($order, 'countryId'));
        $checkout = (string) data_get($order, 'checkout');
        $subject = "Pedido #{$reference} entregado";
        $deliveryLabel = $checkout === 'DOMICILIO'
            ? 'Domicilio'
            : trim('Retiro en tienda '.(string) data_get($order, 'storePickup.storeName', ''));
        $intro = $checkout === 'DOMICILIO'
            ? 'Tu pedido fue entregado en la direccion indicada.'
            : 'Tu pedido fue entregado en tienda.';
        $rows = $checkout === 'DOMICILIO'
            ? [
                'Tipo de entrega' => $deliveryLabel,
                'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
                'Direccion' => (string) data_get($order, 'shipping.address', ''),
                'Costo de envio' => $currency.' '.number_format((float) data_get($order, 'shipping.cost', 0), 2),
                'Total de productos' => (string) data_get($order, 'totals.itemsBilled', data_get($order, 'totals.items')),
                'Total de la compra' => $currency.' '.number_format((float) data_get($order, 'totals.billed', 0), 2),
            ]
            : [
                'Tipo de entrega' => $deliveryLabel,
                'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
                'Total de productos' => (string) data_get($order, 'totals.itemsBilled', data_get($order, 'totals.items')),
                'Total de la compra' => $currency.' '.number_format((float) data_get($order, 'totals.billed', 0), 2),
            ];

        $html = '<!doctype html>
            <html>
            <body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#1f2937;">
                <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 0;">
                    <tr>
                        <td align="center">
                            <table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e5e7eb;">
                                <tr>
                                    <td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;">
                                        <h1 style="margin:0;font-size:24px;color:#111827;">Pedido entregado</h1>
                                        <p style="margin:12px 0 0;font-size:15px;">Hola <strong>'.$customer.'</strong>,</p>
                                        <p style="margin:8px 0 0;font-size:15px;">Tu pedido con numero de referencia '.e($reference).' ha sido entregado. '.e($intro).'</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:22px 28px;">
                                        '.$this->mailKeyValueTable($rows).'
                                        <p style="margin-top:24px;font-size:13px;color:#6b7280;">Gracias por comprar en St. Jack\'s Online.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $products
     */
    private function mailChangeNotice(array $order, array $products, string $currency, float $refund): string
    {
        $itemsOriginal = (int) data_get($order, 'totals.itemsOriginal', 0);
        $itemsBilled = (int) data_get($order, 'totals.itemsBilled', 0);
        $hasChangedProducts = collect($products)->contains(function (array $product): bool {
            return (int) ($product['quantity'] ?? 0) !== (int) ($product['billedQuantity'] ?? 0)
                || (bool) data_get($product, 'substitute.hasSubstitute', false)
                || (bool) data_get($product, 'loggedChange.changed', false);
        });

        $notice = '';

        if ($itemsBilled === 0) {
            $notice .= '<p style="margin-top:18px;">Lamentamos informarle que no podemos facturar su pedido debido a disponibilidad de inventario.</p>';
        } elseif ($itemsOriginal !== $itemsBilled || $hasChangedProducts) {
            $notice .= '<p style="margin-top:18px;">Lamentamos informarle que sus articulos sufrieron cambios debido a disponibilidad de inventario.</p>';
        }

        $notice .= $this->mailProductsTable($products, $hasChangedProducts || $itemsOriginal !== $itemsBilled);

        if ($refund > 0) {
            $notice .= '<p style="margin-top:18px;"><strong>Monto devolucion:</strong> '.$currency.' '.number_format($refund, 2).'</p>';
            $notice .= '<p>Fondos liberados; proceso de devolucion iniciado, en un plazo maximo de 7 dias habiles, el banco emisor de su tarjeta hara efectivo el reintegro de su dinero.</p>';
        }

        return $notice;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function mailProductsTable(array $products, bool $showBilledQuantity): string
    {
        $headers = $showBilledQuantity
            ? ['Sustituido', 'SKU', 'Descripcion', 'Talla', 'Cantidad solicitada', 'Cantidad facturada']
            : ['Sustituido', 'SKU', 'Descripcion', 'Talla', 'Cantidad'];
        $rows = '';

        foreach ($products as $product) {
            $hasSubstitute = (bool) data_get($product, 'substitute.hasSubstitute', false);
            $hasLoggedProductChange = (bool) data_get($product, 'loggedChange.productChanged', false);
            $hasLoggedQuantityChange = (bool) data_get($product, 'loggedChange.quantityChanged', false);
            $hasChange = $hasSubstitute || $hasLoggedProductChange;
            $strike = $hasChange ? 'text-decoration:line-through;' : '';
            $sku = '<span style="'.$strike.'">'.e($hasLoggedProductChange ? (string) data_get($product, 'loggedChange.sku') : (string) ($product['sku'] ?? '')).'</span>';
            $name = '<span style="'.$strike.'">'.e($hasLoggedProductChange ? (string) data_get($product, 'loggedChange.name') : (string) ($product['name'] ?? '')).'</span>';
            $size = '<span style="'.$strike.'">'.e($hasLoggedProductChange ? (string) data_get($product, 'loggedChange.size') : (string) ($product['size'] ?? '')).'</span>';
            $quantity = $hasLoggedQuantityChange ? (int) data_get($product, 'loggedChange.quantity', 0) : (int) ($product['quantity'] ?? 0);

            if ($hasLoggedProductChange) {
                $sku .= '<br>'.e((string) data_get($product, 'loggedChange.newSku'));
                $name .= '<br>'.e((string) data_get($product, 'loggedChange.newName'));
                $size .= '<br>'.e((string) data_get($product, 'loggedChange.newSize'));
            } elseif ($hasSubstitute) {
                $sku .= '<br>'.e((string) data_get($product, 'substitute.sku'));
                $name .= '<br>'.e((string) data_get($product, 'substitute.name'));
                $size .= '<br>'.e((string) data_get($product, 'substitute.size'));
            }

            $rows .= '<tr>
                <td style="border:1px solid #d1d5db;padding:7px;">'.($hasChange ? 'SI' : 'NO').'</td>
                <td style="border:1px solid #d1d5db;padding:7px;">'.$sku.'</td>
                <td style="border:1px solid #d1d5db;padding:7px;">'.$name.'</td>
                <td style="border:1px solid #d1d5db;padding:7px;">'.$size.'</td>
                <td style="border:1px solid #d1d5db;padding:7px;text-align:right;">'.number_format($quantity).'</td>';

            if ($showBilledQuantity) {
                $rows .= '<td style="border:1px solid #d1d5db;padding:7px;text-align:right;">'.number_format((int) ($product['billedQuantity'] ?? 0)).'</td>';
            }

            $rows .= '</tr>';
        }

        return '<table cellpadding="0" cellspacing="0" style="margin-top:18px;border-collapse:collapse;width:100%;font-size:13px;">
            <thead><tr>'.collect($headers)->map(fn (string $header) => '<th style="border:1px solid #d1d5db;padding:7px;background:#f3f4f6;text-align:left;">'.$header.'</th>')->implode('').'</tr></thead>
            <tbody>'.$rows.'</tbody>
        </table>';
    }

    /**
     * @param array<string, string> $rows
     */
    private function mailKeyValueTable(array $rows): string
    {
        return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px;">'
            .collect($rows)
                ->filter(fn (string $value) => trim($value) !== '')
                ->map(fn (string $value, string $label) => '<tr>
                    <td style="border:1px solid #d1d5db;padding:8px;font-weight:bold;width:38%;">'.e($label).'</td>
                    <td style="border:1px solid #d1d5db;padding:8px;">'.e($value).'</td>
                </tr>')
                ->implode('')
            .'</table>';
    }

    private function mailDate(string $value): string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return 'N/D';
        }

        return $value;
    }

    private function currency(int $countryId): string
    {
        return match ($countryId) {
            2 => 'Q',
            3 => 'CRC',
            default => 'USD',
        };
    }

    private function countrySlug(int $countryId): string
    {
        return match ($countryId) {
            2 => 'Guatemala',
            3 => 'CostaRica',
            5 => 'Panama',
            default => 'ElSalvador',
        };
    }

    private function originalQuantityForLine(int $lineId, int $fallback): int
    {
        $loggedOriginal = DB::table('stj_pedidos_detalle_log')
            ->where('pdl_detalle_id', $lineId)
            ->orderBy('pdl_id')
            ->value('pdl_cantidad_anterior');

        return $loggedOriginal !== null ? (int) $loggedOriginal : $fallback;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function logLineChange(object $previous, object $updated, array $actor): void
    {
        DB::table('stj_pedidos_detalle_log')->insert([
            'pdl_pedido_id' => (int) $previous->ped_id,
            'pdl_pago_id' => (int) $previous->ppa_id,
            'pdl_detalle_id' => (int) $previous->car_id,
            'pdl_ref' => (string) $previous->car_ref,
            'pdl_pais' => (int) $previous->ped_id_pais,
            'pdl_usuario_id' => $actor['id'] ?? null,
            'pdl_usuario_nombre' => $actor['name'] ?? $actor['username'] ?? null,
            'pdl_usuario_correo' => $actor['email'] ?? null,
            'pdl_usuario_operaciones' => $this->json($actor['permissions'] ?? []),
            'pdl_ip' => $actor['ip'] ?? Request::ip(),
            'pdl_user_agent' => $actor['userAgent'] ?? request()->userAgent(),
            'pdl_origen' => 'stj-dashboard',
            'pdl_producto_id_anterior' => (int) $previous->car_producto,
            'pdl_codigo_anterior' => (string) $previous->pro_codigo,
            'pdl_nombre_anterior' => (string) $previous->pro_nombre,
            'pdl_talla_anterior' => (string) ($previous->car_talla ?? ''),
            'pdl_cantidad_anterior' => (int) ($previous->car_cantidad ?? 0),
            'pdl_precio_anterior' => (float) ($previous->car_precio ?? 0),
            'pdl_descuento_anterior' => (float) ($previous->car_descuento ?? 0),
            'pdl_producto_id_nuevo' => (int) $updated->car_producto,
            'pdl_codigo_nuevo' => (string) $updated->pro_codigo,
            'pdl_nombre_nuevo' => (string) $updated->pro_nombre,
            'pdl_talla_nueva' => (string) ($updated->car_talla ?? ''),
            'pdl_cantidad_nueva' => (int) ($updated->car_cantidad ?? 0),
            'pdl_precio_nuevo' => (float) ($updated->car_precio ?? 0),
            'pdl_descuento_nuevo' => (float) ($updated->car_descuento ?? 0),
            'pdl_total_facturado_anterior' => $previous->car_total_facturado,
            'pdl_total_facturado_nuevo' => $updated->car_total_facturado,
            'pdl_estilo_final_anterior' => $previous->car_estilo_final,
            'pdl_estilo_final_nuevo' => $updated->car_estilo_final,
            'pdl_talla_final_anterior' => $previous->car_talla_final,
            'pdl_talla_final_nueva' => $updated->car_talla_final,
            'pdl_descuento_final_anterior' => $previous->car_descuento_final,
            'pdl_descuento_final_nuevo' => $updated->car_descuento_final,
            'pdl_snapshot_anterior' => $this->json($previous),
            'pdl_snapshot_nuevo' => $this->json($updated),
            'pdl_motivo' => 'Edicion manual de detalle de pedido',
            'pdl_fecha' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function actorName(array $actor): string
    {
        return (string) ($actor['username'] ?? $actor['email'] ?? $actor['name'] ?? 'stj-dashboard');
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function order(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_direccion as pd', 'p.ped_id', '=', 'pd.pdi_pedido')
            ->leftJoin('stj_direcciones as d', 'pd.pdi_direccion', '=', 'd.dir_id')
            ->leftJoin('stj_pedidos_tienda as pt', 'p.ped_id', '=', 'pt.pti_pedido')
            ->leftJoin('stj_tiendas as pickup_store', function ($join) use ($countryId) {
                $join->on('pickup_store.tie_codigo', '=', 'pt.pti_tienda')
                    ->where('pickup_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_tiendas as order_store', function ($join) use ($countryId) {
                $join->on('order_store.tie_codigo', '=', 'p.ped_tienda')
                    ->where('order_store.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_mensajes_fac as mf', function ($join) {
                $join->on('mf.mfa_tarjeta', '=', 'pay.ppa_emisor')
                    ->on('mf.mfa_codigo', '=', 'pay.ppa_rsp_codigo');
            })
            ->where('pay.ppa_ref', $reference)
            ->where('pay.ppa_estado', 'APROBADA')
            ->where('p.ped_id_pais', $countryId)
            ->select([
                'p.*',
                'pd.*',
                'd.*',
                'pt.*',
                'pay.*',
                'mf.*',
            ])
            ->selectRaw('
                COALESCE(pickup_store.tie_codigo, order_store.tie_codigo, pt.pti_tienda, p.ped_tienda) AS tie_codigo,
                COALESCE(pickup_store.tie_nombre, order_store.tie_nombre) AS tie_nombre
            ')
            ->first();
    }

    private function shippingManagementOrder(string $reference, bool $lock = false): ?object
    {
        $query = DB::table('stj_pedidos_pago as pay')
            ->join('stj_pedidos as p', 'p.ped_id', '=', 'pay.ppa_pedido')
            ->leftJoin('stj_pedidos_direccion as pd', 'pd.pdi_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_direcciones as d', 'd.dir_id', '=', 'pd.pdi_direccion')
            ->where('pay.ppa_ref', trim($reference))
            ->select([
                'pay.ppa_ref',
                'p.ped_id',
                'p.ped_id_pais',
                'p.ped_checkout',
                'p.ped_estatus',
                'pd.pdi_id',
                'pd.pdi_pedido',
                'pd.pdi_direccion',
                'pd.pdi_tipo_envio',
                'pd.pdi_id_urbano',
                'pd.pdi_id_shipping',
                'pd.pdi_costo_envio',
                'pd.pdi_costo_envio_txt',
                'pd.pdi_costo_envio_final',
                'pd.pdi_aplica_envio_gratis',
                'pd.pdi_fecha_ruta',
                'd.dir_id',
                'd.dir_tipo',
                'd.dir_misma_persona',
                'd.dir_misma_direccion',
                'd.dir_fecha',
                'd.dir_usuario',
                'd.dir_pais',
                'd.dir_latitud',
                'd.dir_longitud',
                'd.dir_direccion',
                'd.dir_referencia',
                'd.dir_departamento',
                'd.dir_municipio',
                'd.dir_departamento_txt',
                'd.dir_municipio_txt',
                'd.dir_distrito',
                'd.dir_persona',
                'd.dir_telefono',
                'd.dir_save',
            ])
            ->orderByDesc('pay.ppa_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function normalizeShippingManagement(object $order): array
    {
        $checkout = strtoupper((string) ($order->ped_checkout ?? ''));

        return [
            'reference' => (string) ($order->ppa_ref ?? ''),
            'orderId' => (int) ($order->ped_id ?? 0),
            'countryId' => (int) ($order->ped_id_pais ?? 0),
            'checkout' => $checkout,
            'status' => (string) ($order->ped_estatus ?? ''),
            'isHomeDelivery' => $checkout === 'DOMICILIO',
            'orderShipping' => $checkout === 'DOMICILIO' ? [
                'id' => $order->pdi_id !== null ? (int) $order->pdi_id : null,
                'orderId' => $order->pdi_pedido !== null ? (int) $order->pdi_pedido : null,
                'addressId' => $order->pdi_direccion !== null ? (int) $order->pdi_direccion : null,
                'shippingType' => (string) ($order->pdi_tipo_envio ?? ''),
                'urbanId' => (string) ($order->pdi_id_urbano ?? ''),
                'shippingId' => (string) ($order->pdi_id_shipping ?? ''),
                'shippingCost' => (float) ($order->pdi_costo_envio ?? 0),
                'shippingCostText' => (string) ($order->pdi_costo_envio_txt ?? ''),
                'finalShippingCost' => (float) ($order->pdi_costo_envio_final ?? 0),
                'freeShipping' => (string) ($order->pdi_aplica_envio_gratis ?? 'NO'),
                'routeAt' => filled($order->pdi_fecha_ruta ?? null)
                    ? Carbon::parse($order->pdi_fecha_ruta)->format('Y-m-d\TH:i')
                    : '',
            ] : null,
            'address' => $checkout === 'DOMICILIO' ? [
                'id' => $order->dir_id !== null ? (int) $order->dir_id : null,
                'addressType' => (string) ($order->dir_tipo ?? ''),
                'samePerson' => (string) ($order->dir_misma_persona ?? 'NO'),
                'sameAddress' => (string) ($order->dir_misma_direccion ?? 'NO'),
                'createdAt' => (string) ($order->dir_fecha ?? ''),
                'userId' => (string) ($order->dir_usuario ?? ''),
                'country' => (string) ($order->dir_pais ?? ''),
                'latitude' => (string) ($order->dir_latitud ?? ''),
                'longitude' => (string) ($order->dir_longitud ?? ''),
                'address' => (string) ($order->dir_direccion ?? ''),
                'referencePoint' => (string) ($order->dir_referencia ?? ''),
                'departmentId' => (string) ($order->dir_departamento ?? ''),
                'municipalityId' => (string) ($order->dir_municipio ?? ''),
                'department' => (string) ($order->dir_departamento_txt ?? ''),
                'municipality' => (string) ($order->dir_municipio_txt ?? ''),
                'district' => (string) ($order->dir_distrito ?? ''),
                'receiverName' => (string) ($order->dir_persona ?? ''),
                'receiverPhone' => (string) ($order->dir_telefono ?? ''),
                'saveType' => (string) ($order->dir_save ?? ''),
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function ensureRootActor(array $actor): void
    {
        $permissions = collect($actor['permissions'] ?? [])
            ->map(fn ($permission) => strtoupper(trim((string) $permission)))
            ->all();

        if (! in_array('ROOT', $permissions, true)) {
            throw ValidationException::withMessages([
                'actor' => 'Solo un usuario ROOT puede gestionar los datos de envio del pedido.',
            ]);
        }
    }

    private function applyOrderSearchTerm($query, string $like): void
    {
        $query
            ->orWhere('pay.ppa_ref', 'like', $like)
            ->orWhere('p.ped_id', 'like', $like)
            ->orWhere('pay.ppa_id', 'like', $like)
            ->orWhere('p.ped_nombres', 'like', $like)
            ->orWhere('p.ped_apellidos', 'like', $like)
            ->orWhereRaw("CONCAT_WS(' ', p.ped_nombres, p.ped_apellidos) LIKE ?", [$like])
            ->orWhere('p.ped_email', 'like', $like)
            ->orWhere('p.ped_identificacion', 'like', $like)
            ->orWhere('p.ped_telefono', 'like', $like)
            ->orWhere('p.ped_whatsapp', 'like', $like)
            ->orWhere('p.ped_sesion', 'like', $like)
            ->orWhere('pay.ppa_tarjeta', 'like', $like)
            ->orWhere('d.dir_direccion', 'like', $like)
            ->orWhere('d.dir_municipio_txt', 'like', $like)
            ->orWhere('d.dir_departamento_txt', 'like', $like)
            ->orWhere('d.dir_referencia', 'like', $like)
            ->orWhere('p.ped_direccion', 'like', $like)
            ->orWhere('p.ped_ciudad', 'like', $like)
            ->orWhere('p.ped_estado', 'like', $like);
    }

    private function normalizeSearchOrder(object $row): array
    {
        $paymentType = (string) ($row->ppa_tipo ?? '');
        $shippingAddress = $this->joinAddress([$row->direccion_envio ?? null]);
        $billingAddress = $this->joinAddress([
            $row->ped_direccion ?? null,
            $row->ped_ciudad ?? null,
            $row->ped_estado ?? null,
            $row->ped_pais ?? null,
        ]);

        return [
            'id' => (int) $row->ped_id,
            'paymentId' => $row->ppa_id !== null ? (int) $row->ppa_id : null,
            'countryId' => (int) $row->ped_id_pais,
            'storeCode' => (string) ($row->tie_codigo ?? ''),
            'storeId' => $row->tie_id !== null ? (int) $row->tie_id : null,
            'storeName' => (string) ($row->tie_nombre ?? ''),
            'origin' => (string) ($row->ped_origen ?? ''),
            'checkout' => (string) ($row->ped_checkout ?? ''),
            'status' => (string) ($row->ped_estatus ?? ''),
            'productStatus' => (string) ($row->ped_estatus_productos ?? ''),
            'ref' => (string) ($row->ppa_ref ?? ''),
            'paymentStatus' => (string) ($row->ppa_estado ?? ''),
            'createdAt' => (string) ($row->ped_fecha ?? ''),
            'paidAt' => (string) ($row->ppa_fecha ?? $row->ped_fecha ?? ''),
            'customer' => trim((string) ($row->ped_nombres ?? '').' '.(string) ($row->ped_apellidos ?? '')),
            'identification' => (string) ($row->ped_identificacion ?? ''),
            'email' => (string) ($row->ped_email ?? ''),
            'phone' => (string) ($row->ped_telefono ?? ''),
            'whatsapp' => (string) ($row->ped_whatsapp ?? ''),
            'session' => (string) ($row->ped_sesion ?? ''),
            'paymentType' => $paymentType,
            'issuer' => $paymentType === 'EFECTIVO' ? 'EFECTIVO' : (string) ($row->ppa_emisor ?? ''),
            'cardOrChange' => $paymentType === 'EFECTIVO'
                ? 'Cambio: '.(string) ($row->ppa_cambio ?? '')
                : (string) ($row->ppa_tarjeta ?? ''),
            'amount' => (float) ($row->ppa_monto_senv ?? $row->ppa_monto ?? 0),
            'items' => (int) ($row->ppa_articulos ?? 0),
            'address' => (string) ($row->ped_checkout ?? '') === 'DOMICILIO' ? $shippingAddress : $billingAddress,
            'destination' => (string) ($row->ped_checkout ?? '') === 'DOMICILIO'
                ? $shippingAddress
                : 'Tienda: '.(string) ($row->tie_nombre ?? ''),
        ];
    }

    private function normalizeRefund(object $row): array
    {
        $payload = $this->servicePayload($row->ped_rsp_servicio ?? null);
        $approved = $this->serviceApproved($payload);
        $raw = $this->servicePayloadText($row->ped_rsp_servicio ?? null, $payload);
        $refundStatus = strtoupper((string) ($row->ped_devolucion_realizada ?? ''));

        return [
            'id' => (int) $row->ped_id,
            'countryId' => (int) $row->ped_id_pais,
            'ref' => (string) ($row->ppa_ref ?? ''),
            'paidAt' => (string) ($row->ppa_fecha ?? ''),
            'refundAt' => (string) ($row->ped_fecha_devolucion ?? ''),
            'status' => (string) ($row->ped_estatus ?? ''),
            'refundStatus' => in_array($refundStatus, ['SI', 'NO'], true) ? $refundStatus : '',
            'refundLabel' => $refundStatus === 'SI' ? 'Devolucion procesada' : 'Devolucion pendiente',
            'serviceApproved' => $approved,
            'serviceRejected' => $refundStatus === 'SI' && $approved === false,
            'origin' => (string) ($row->ped_origen ?? ''),
            'checkout' => (string) ($row->ped_checkout ?? ''),
            'storeCode' => (string) ($row->tie_codigo ?? ''),
            'storeId' => $row->tie_id !== null ? (int) $row->tie_id : null,
            'storeName' => (string) ($row->tie_nombre ?? ''),
            'customer' => trim((string) ($row->ped_nombres ?? '').' '.(string) ($row->ped_apellidos ?? '')),
            'identification' => (string) ($row->ped_identificacion ?? ''),
            'email' => (string) ($row->ped_email ?? ''),
            'phone' => (string) ($row->ped_telefono ?? ''),
            'whatsapp' => (string) ($row->ped_whatsapp ?? ''),
            'paymentType' => (string) ($row->ppa_tipo ?? ''),
            'items' => (int) ($row->ppa_articulos ?? 0),
            'paidAmount' => (float) ($row->ppa_monto ?? 0),
            'amount' => (float) ($row->ppa_monto_senv ?? $row->ppa_monto ?? 0),
            'refundAmount' => (float) ($row->ped_monto_devolucion ?? 0),
            'refundObservation' => (string) ($row->ped_observacion_devolucion ?? ''),
            'servicePayload' => $payload,
            'serviceRaw' => $raw,
        ];
    }

    private function refundStatus(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (! in_array($value, ['SI', 'NO'], true)) {
            throw ValidationException::withMessages([
                'status' => 'El estado de devolucion no es valido.',
            ]);
        }

        return $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * @return mixed
     */
    private function servicePayload(mixed $value): mixed
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        try {
            $unserialized = @unserialize($raw);

            if ($unserialized !== false || $raw === 'b:0;') {
                return json_decode(json_encode($unserialized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null', true);
            }
        } catch (Throwable) {
        }

        $json = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        return $raw;
    }

    private function servicePayloadText(mixed $value, mixed $payload): string
    {
        if (is_array($payload)) {
            return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim((string) $value);
    }

    private function serviceApproved(mixed $payload): ?bool
    {
        if (! is_array($payload)) {
            return null;
        }

        $approved = data_get($payload, 'Approved')
            ?? data_get($payload, 'approved')
            ?? data_get($payload, 'Response.Approved')
            ?? data_get($payload, 'response.Approved')
            ?? data_get($payload, 'response.approved');

        if ($approved === null) {
            return null;
        }

        return filter_var($approved, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function paymentAttemptDetail(mixed $value): string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return '';
        }

        try {
            $unserialized = @unserialize($raw);

            if ($unserialized !== false || $raw === 'b:0;') {
                return print_r($unserialized, true);
            }
        } catch (Throwable) {
        }

        $json = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $raw;
    }

    private function products(string $reference, int $countryId): array
    {
        return DB::table('stj_pedidos_detalle as detail')
            ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->join('stj_producto_pais as country_product', function ($join) use ($countryId) {
                $join->on('country_product.ppa_producto', '=', 'product.pro_id')
                    ->where('country_product.ppa_pais', '=', $countryId);
            })
            ->where('detail.car_ref', $reference)
            ->where('detail.car_accion', 'AGREGADO')
            ->where('detail.car_pais', $countryId)
            ->selectRaw("
                detail.*,
                product.pro_codigo,
                product.pro_nombre,
                country_product.ppa_precio,
                (SELECT sp.pro_nombre FROM stj_productos sp WHERE sp.pro_codigo = detail.car_estilo_final LIMIT 1) AS estilo_final_nombre
            ")
            ->get()
            ->map(fn ($product) => $this->normalizeProduct($product))
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function normalizeOrder(object $order, array $products): array
    {
        $shipping = (float) ($order->pdi_costo_envio_final ?? 0);
        $chargedSubtotal = collect($products)->sum(fn (array $product) => (float) ($product['chargedSubtotal'] ?? 0));
        $billedSubtotal = collect($products)->sum(fn (array $product) => (float) ($product['billedSubtotal'] ?? 0));
        $items = collect($products)->sum(fn (array $product) => (int) ($product['quantity'] ?? 0));
        $itemsBilled = collect($products)->sum(fn (array $product) => (int) ($product['billedQuantity'] ?? 0));
        $productsTotal = round($chargedSubtotal, 2);
        $productsOriginal = round((float) ($order->ppa_monto_senv ?? 0), 2);
        $paid = round((float) ($order->ppa_monto ?? 0), 2);
        $paidCalculated = round($chargedSubtotal + ((string) ($order->ped_checkout ?? '') === 'DOMICILIO' ? $shipping : 0), 2);
        $refundStatus = strtoupper((string) ($order->ped_devolucion_realizada ?? ''));
        $refundAmount = (float) ($order->ped_monto_devolucion ?? 0);
        $refundPayload = $this->servicePayload($order->ped_rsp_servicio ?? null);
        $billed = round(max(0, $billedSubtotal + ((string) ($order->ped_checkout ?? '') === 'DOMICILIO' ? $shipping : 0)), 2);

        return [
            'id' => (int) $order->ped_id,
            'paymentId' => (int) $order->ppa_id,
            'reference' => (string) $order->ppa_ref,
            'countryId' => (int) $order->ped_id_pais,
            'origin' => (string) ($order->ped_origen ?? ''),
            'status' => (string) ($order->ped_estatus ?? ''),
            'productStatus' => (string) ($order->ped_estatus_productos ?? ''),
            'checkout' => (string) ($order->ped_checkout ?? ''),
            'createdAt' => (string) ($order->ped_fecha ?? ''),
            'paidAt' => (string) ($order->ppa_fecha ?? ''),
            'processedAt' => (string) ($order->ppa_fecha_procesado ?? ''),
            'deliveredAt' => (string) ($order->ppa_fecha_entregado ?? ''),
            'customer' => [
                'name' => trim((string) ($order->ped_nombres ?? '').' '.(string) ($order->ped_apellidos ?? '')),
                'email' => (string) ($order->ped_email ?? ''),
                'identificationType' => (string) ($order->ped_tipo_identificacion ?? ''),
                'identification' => (string) ($order->ped_identificacion ?? ''),
                'rtu' => (string) ($order->ped_rtu ?? ''),
                'phone' => trim((string) ($order->ped_telefono_pais ?? '').' '.(string) ($order->ped_telefono ?? '')),
                'phoneRaw' => (string) ($order->ped_telefono ?? ''),
                'whatsapp' => trim((string) ($order->ped_whatsapp_pais ?? '').' '.(string) ($order->ped_whatsapp ?? '')),
                'whatsappRaw' => (string) ($order->ped_whatsapp ?? ''),
                'billingAddress' => $this->joinAddress([
                    $order->ped_direccion ?? null,
                    $order->ped_ciudad ?? null,
                    $order->ped_estado ?? null,
                    $order->ped_pais ?? null,
                ]),
                'billingAddressRaw' => (string) ($order->ped_direccion ?? ''),
            ],
            'payment' => [
                'type' => (string) ($order->ppa_tipo ?? ''),
                'status' => (string) ($order->ppa_estado ?? ''),
                'issuer' => (string) ($order->ppa_emisor ?? ''),
                'card' => (string) ($order->ppa_tarjeta ?? ''),
                'authorization' => (string) ($order->ppa_autorizacion ?? ''),
                'change' => (float) ($order->ppa_cambio ?? 0),
                'ticket' => (string) ($order->ppa_ticket ?? ''),
                'responseCode' => (string) ($order->ppa_rsp_codigo ?? ''),
                'message' => (string) ($order->mfa_mensaje ?? ''),
            ],
            'refund' => [
                'hasRefund' => in_array($refundStatus, ['SI', 'NO'], true) && $refundAmount > 0,
                'status' => in_array($refundStatus, ['SI', 'NO'], true) ? $refundStatus : 'N/A',
                'label' => $refundStatus === 'SI' ? 'Devolucion procesada' : ($refundStatus === 'NO' ? 'Devolucion pendiente' : 'N/A'),
                'amount' => $refundAmount,
                'date' => (string) ($order->ped_fecha_devolucion ?? ''),
                'observation' => (string) ($order->ped_observacion_devolucion ?? ''),
                'approved' => $this->serviceApproved($refundPayload),
                'servicePayload' => $refundPayload,
                'serviceRaw' => $this->servicePayloadText($order->ped_rsp_servicio ?? null, $refundPayload),
            ],
            'shipping' => [
                'id' => $order->pdi_id !== null ? (int) $order->pdi_id : null,
                'shippingId' => (string) ($order->pdi_id_shipping ?? ''),
                'addressId' => $order->dir_id !== null ? (int) $order->dir_id : null,
                'address' => $this->joinAddress([
                    $order->dir_direccion ?? null,
                    $order->dir_municipio_txt ?? null,
                    $order->dir_departamento_txt ?? null,
                ]),
                'addressRaw' => (string) ($order->dir_direccion ?? ''),
                'reference' => (string) ($order->dir_referencia ?? ''),
                'lat' => (string) ($order->dir_latitud ?? ''),
                'lng' => (string) ($order->dir_longitud ?? ''),
                'cost' => $shipping,
                'routeAt' => (string) ($order->pdi_fecha_ruta ?? ''),
                'samePerson' => (string) ($order->dir_misma_persona ?? ''),
                'receiverName' => (string) ($order->dir_persona ?? ''),
                'receiverPhone' => (string) ($order->dir_telefono ?? ''),
            ],
            'storePickup' => [
                'storeCode' => (string) ($order->tie_codigo ?? $order->ped_tienda ?? ''),
                'storeName' => (string) ($order->tie_nombre ?? ''),
                'samePerson' => (string) ($order->pti_misma_persona ?? ''),
                'person' => (string) ($order->pti_persona ?? ''),
                'phone' => (string) ($order->pti_telefono ?? ''),
                'identification' => (string) ($order->pti_identificacion ?? ''),
            ],
            'totals' => [
                'items' => $items,
                'itemsOriginal' => (int) ($order->ppa_articulos ?? 0),
                'itemsBilled' => $itemsBilled,
                'itemsBilledOriginal' => (int) ($order->ppa_articulos_final ?? 0),
                'products' => $productsTotal,
                'productsOriginal' => $productsOriginal,
                'productsDifference' => round($productsTotal - $productsOriginal, 2),
                'shipping' => (string) ($order->ped_checkout ?? '') === 'DOMICILIO' ? $shipping : 0.0,
                'paid' => $paid,
                'paidCalculated' => $paidCalculated,
                'paidDifference' => round($paidCalculated - $paid, 2),
                'discount' => (float) ($order->ppa_promo_descuento ?? 0),
                'refund' => $refundAmount,
                'billed' => $billed,
                'billedNet' => round(max(0, $billed - $refundAmount), 2),
            ],
        ];
    }

    private function normalizeProduct(object $product): array
    {
        $quantity = (int) ($product->car_cantidad ?? 0);
        $originalQuantity = (int) ($product->car_cantidad_copia ?? 0) > 0
            ? (int) $product->car_cantidad_copia
            : null;
        $billedQuantity = $product->car_total_facturado !== null ? (int) $product->car_total_facturado : null;
        $price = (float) ($product->car_precio ?? $product->ppa_precio ?? 0);
        $discount = (float) ($product->car_descuento ?? 0);
        $billedDiscount = (float) ($product->car_descuento_final ?? $discount);
        $hasSubstitute = filled($product->car_estilo_final)
            && ((string) $product->pro_codigo !== (string) $product->car_estilo_final
                || (string) $product->car_talla !== (string) $product->car_talla_final);

        return [
            'id' => (int) $product->car_id,
            'sku' => (string) $product->pro_codigo,
            'name' => (string) $product->pro_nombre,
            'size' => (string) ($product->car_talla ?? ''),
            'quantity' => $quantity,
            'originalQuantity' => $originalQuantity,
            'quantityEdited' => $originalQuantity !== null && $originalQuantity !== $quantity,
            'billedQuantity' => $billedQuantity,
            'price' => $price,
            'discount' => $discount,
            'billedDiscount' => $billedDiscount,
            'chargedSubtotal' => $quantity * ($price * (1 - ($discount / 100))),
            'billedSubtotal' => ($billedQuantity ?? 0) * ($price * (1 - ($billedDiscount / 100))),
            'promotionId' => $product->car_promocion_id !== null ? (int) $product->car_promocion_id : null,
            'promotion' => (string) ($product->car_promocion ?? ''),
            'substitute' => [
                'hasSubstitute' => $hasSubstitute,
                'sku' => (string) ($product->car_estilo_final ?? ''),
                'name' => (string) ($product->estilo_final_nombre ?? ''),
                'size' => (string) ($product->car_talla_final ?? ''),
            ],
        ];
    }

    /**
     * @return array{id: int, sku: string, name: string, price: float, status: string, sizes: array<int, string>}
     */
    private function resolveActiveProduct(string $sku, int $countryId): array
    {
        $product = DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->where('p.pro_codigo', trim($sku))
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_nombre',
                'p.pro_tallas',
                'pp.ppa_precio',
                'pp.ppa_estado',
            ])
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'sku' => 'El articulo no existe o no esta activo para el pais del pedido.',
            ]);
        }

        return [
            'id' => (int) $product->pro_id,
            'sku' => (string) $product->pro_codigo,
            'name' => (string) $product->pro_nombre,
            'price' => (float) $product->ppa_precio,
            'status' => (string) $product->ppa_estado,
            'sizes' => $this->sizes((string) ($product->pro_tallas ?? '')),
        ];
    }

    /**
     * @param array{id: int, sku: string, name: string, price: float, status: string, sizes: array<int, string>} $product
     */
    private function ensureValidSize(array $product, string $size): void
    {
        $size = strtoupper(trim($size));
        $sizes = array_map(fn (string $value) => strtoupper($value), $product['sizes']);

        if ($sizes !== [] && ! in_array($size, $sizes, true)) {
            throw ValidationException::withMessages([
                'size' => 'La talla no existe para el articulo seleccionado. Tallas validas: '.implode(', ', $product['sizes']),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function sizes(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $size) => trim($size))
            ->filter()
            ->values()
            ->all();
    }

    private function joinAddress(array $parts): string
    {
        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');
    }

    private function trimText(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) $value), 0, $limit);
    }

    private function nullableTrimText(mixed $value, int $limit): ?string
    {
        $value = $this->trimText($value, $limit);

        return $value !== '' ? $value : null;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Carbon::parse($value)->format('Y-m-d H:i:s') : null;
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
     * @return array{code: ?string, id: ?int, name: ?string}|null
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
}
