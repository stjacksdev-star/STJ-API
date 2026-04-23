<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    public function __construct(
        private readonly PromotionProductImportService $imports,
    ) {
    }

    public function index(?string $country = null, ?string $status = null, int $limit = 200): array
    {
        $countryId = $this->resolveCountryId($country);
        $status = trim((string) $status);

        $schedule = DB::table('stj_promociones_horario')
            ->selectRaw('pho_promocion, MIN(pho_inicio) as pho_inicio, MAX(pho_fin) as pho_fin, MAX(pho_estado) as pho_estado')
            ->where('pho_tipo', 'NORMAL')
            ->groupBy('pho_promocion');

        $products = DB::table('stj_promociones_producto')
            ->selectRaw('ppr_promocion, COUNT(*) as products_count')
            ->groupBy('ppr_promocion');

        $assets = DB::table('stj_assets')
            ->selectRaw('ast_idpromocion, COUNT(*) as assets_count')
            ->where('ast_tipo_accion', 1)
            ->groupBy('ast_idpromocion');

        $query = DB::table('stj_promociones as p')
            ->leftJoin('stj_paises as c', 'c.pai_id', '=', 'p.prm_pais')
            ->leftJoinSub($schedule, 'h', 'h.pho_promocion', '=', 'p.prm_id')
            ->leftJoinSub($products, 'pp', 'pp.ppr_promocion', '=', 'p.prm_id')
            ->leftJoinSub($assets, 'a', 'a.ast_idpromocion', '=', 'p.prm_id')
            ->select([
                'p.prm_id',
                'p.prm_ticket',
                'p.prm_pais',
                'p.prm_origen',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
                'p.prm_modalidad',
                'p.prm_tipo',
                'p.prm_estado',
                'p.prm_tipo_promocion',
                'p.prm_aplica',
                'p.prm_precio',
                'p.prm_porcentaje',
                'p.prm_restriccion',
                'p.prm_fecha',
                'p.prm_grid_promo',
                'p.prm_encabezado',
                'h.pho_inicio',
                'h.pho_fin',
                'h.pho_estado',
                'c.pai_codigo',
                'c.pai_nombre',
                DB::raw('COALESCE(pp.products_count, 0) as products_count'),
                DB::raw('COALESCE(a.assets_count, 0) as assets_count'),
            ])
            ->when($countryId !== null, fn ($builder) => $builder->where('p.prm_pais', $countryId))
            ->when($status !== '', fn ($builder) => $builder->where('p.prm_estado', $status))
            ->orderByDesc('p.prm_id')
            ->limit(max(1, min($limit, 500)));

        return [
            'filters' => [
                'country' => $countryId,
                'countryCode' => $country ? strtoupper($country) : null,
                'status' => $status !== '' ? $status : null,
                'limit' => $limit,
            ],
            'countries' => $this->countries(),
            'statuses' => ['PENDIENTE', 'EN-PROCESO', 'FINALIZADA', 'CANCELADO', 'SUSPENDIDO'],
            'options' => $this->options(),
            'promotions' => $query
                ->get()
                ->map(fn ($promotion) => $this->normalizePromotion($promotion))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?UploadedFile $products = null): array
    {
        $country = $this->resolveCountry($data['country'] ?? null);
        $startAt = Carbon::parse((string) $data['startAt']);
        $endAt = Carbon::parse((string) $data['endAt']);
        $type = (string) $data['type'];
        $promotionType = (string) $data['promotionType'];
        $productRows = [];

        if ($startAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'startAt' => 'La fecha inicial no puede ser menor o igual a la fecha y hora actual.',
            ]);
        }

        if ($startAt->greaterThanOrEqualTo($endAt)) {
            throw ValidationException::withMessages([
                'endAt' => 'La fecha inicial es mayor a la fecha final.',
            ]);
        }

        if ($type !== 'TODO') {
            if (! $products) {
                throw ValidationException::withMessages([
                    'products' => 'Debe adjuntar el Excel de productos para este tipo de promocion.',
                ]);
            }

            $productRows = $this->resolveProducts(
                $this->imports->read($products),
                $country['id'],
                $promotionType,
            );
        }

        $id = DB::transaction(function () use ($data, $country, $startAt, $endAt, $type, $productRows) {
            $promotionId = DB::table('stj_promociones')->insertGetId([
                'prm_ticket' => '0000',
                'prm_pais' => $country['id'],
                'prm_origen' => $data['origin'],
                'prm_nombre' => trim((string) $data['name']),
                'prm_modalidad' => 'PROGRAMADO',
                'prm_tipo' => $type,
                'prm_categoria' => null,
                'prm_sub_categoria' => null,
                'prm_estado' => 'PENDIENTE',
                'prm_tipo_promocion' => $data['promotionType'],
                'prm_aplica' => 'TODO',
                'prm_precio' => $this->decimalOrNull($data['price'] ?? null),
                'prm_precio_t' => null,
                'prm_precio_d' => null,
                'prm_porcentaje' => $this->decimalOrNull($data['percentage'] ?? null),
                'prm_porcentaje_t' => null,
                'prm_porcentaje_d' => null,
                'prm_restriccion' => $this->stringOrNull($data['restriction'] ?? null),
                'prm_descuento_maximo' => null,
                'prm_eliminar_otras' => null,
                'prm_condicion' => null,
                'prm_valor' => null,
                'prm_tipo_checkout' => $data['checkoutType'] ?? 'TODO',
                'prm_bines' => null,
                'prm_tiendas' => null,
                'prm_logo' => null,
                'prm_fecha' => now(),
                'prm_cancelado_motivo' => null,
                'prm_cancelado_fecha' => null,
                'prm_tomar' => null,
                'prm_cupon_header' => null,
                'prm_encabezado' => 'RUTA',
                'prm_grid_promo' => 'S',
                'prm_modal' => null,
                'prm_modal_image' => null,
                'prm_nombre_comercial' => $this->stringOrNull($data['commercialName'] ?? null),
            ]);

            DB::table('stj_promociones_horario')->insert([
                'pho_tipo' => 'NORMAL',
                'pho_promocion' => $promotionId,
                'pho_inicio' => $startAt->format('Y-m-d H:i:s'),
                'pho_fin' => $endAt->format('Y-m-d H:i:s'),
                'pho_estado' => 'PENDIENTE',
            ]);

            if ($productRows !== []) {
                DB::table('stj_promociones_producto')->insert(
                    array_map(fn (array $row) => [
                        'ppr_promocion' => $promotionId,
                        'ppr_producto' => $row['productId'],
                        'ppr_descuento' => $row['discount'],
                        'ppr_precio' => $row['price'],
                    ], $productRows),
                );
            }

            return $promotionId;
        });

        return $this->find($id);
    }

    public function updateSchedule(int $id, array $data): array
    {
        $promotion = DB::table('stj_promociones')
            ->where('prm_id', $id)
            ->first();

        if (! $promotion) {
            throw ValidationException::withMessages([
                'promotion' => 'La promocion seleccionada no existe.',
            ]);
        }

        if ((string) $promotion->prm_estado === 'FINALIZADA') {
            throw ValidationException::withMessages([
                'promotion' => 'No se pueden modificar promociones finalizadas.',
            ]);
        }

        $promotionUpdates = [];
        $updatesSchedule = array_key_exists('startAt', $data) || array_key_exists('endAt', $data);

        if (array_key_exists('commercialName', $data)) {
            $promotionUpdates['prm_nombre_comercial'] = $this->stringOrNull($data['commercialName']);
        }

        if (! $updatesSchedule) {
            if ($promotionUpdates !== []) {
                DB::table('stj_promociones')
                    ->where('prm_id', $id)
                    ->update($promotionUpdates);
            }

            return $this->find($id);
        }

        $schedule = DB::table('stj_promociones_horario')
            ->where('pho_promocion', $id)
            ->where('pho_tipo', 'NORMAL')
            ->orderByDesc('pho_id')
            ->first();

        if (! $schedule) {
            throw ValidationException::withMessages([
                'schedule' => 'La promocion no tiene horario normal configurado.',
            ]);
        }

        $status = (string) $promotion->prm_estado;
        $startAt = Carbon::parse((string) ($data['startAt'] ?? $schedule->pho_inicio));
        $endAt = Carbon::parse((string) ($data['endAt'] ?? $schedule->pho_fin));

        if ($status === 'PENDIENTE') {
            if ($startAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'startAt' => 'La fecha inicial no puede ser menor o igual a la fecha y hora actual.',
                ]);
            }

            if ($startAt->greaterThanOrEqualTo($endAt)) {
                throw ValidationException::withMessages([
                    'endAt' => 'La fecha inicial es mayor a la fecha final.',
                ]);
            }

            DB::transaction(function () use ($id, $schedule, $startAt, $endAt, $promotionUpdates) {
                if ($promotionUpdates !== []) {
                    DB::table('stj_promociones')
                        ->where('prm_id', $id)
                        ->update($promotionUpdates);
                }

                DB::table('stj_promociones_horario')
                    ->where('pho_id', $schedule->pho_id)
                    ->update([
                        'pho_inicio' => $startAt->format('Y-m-d H:i:s'),
                        'pho_fin' => $endAt->format('Y-m-d H:i:s'),
                    ]);
            });

            return $this->find($id);
        }

        if ($status === 'EN-PROCESO') {
            $currentStart = Carbon::parse((string) $schedule->pho_inicio);

            if ($endAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'endAt' => 'La fecha final debe ser mayor a la fecha y hora actual.',
                ]);
            }

            if ($endAt->lessThanOrEqualTo($currentStart)) {
                throw ValidationException::withMessages([
                    'endAt' => 'La fecha final debe ser mayor a la fecha inicial.',
                ]);
            }

            DB::transaction(function () use ($id, $schedule, $endAt, $promotionUpdates) {
                if ($promotionUpdates !== []) {
                    DB::table('stj_promociones')
                        ->where('prm_id', $id)
                        ->update($promotionUpdates);
                }

                DB::table('stj_promociones_horario')
                    ->where('pho_id', $schedule->pho_id)
                    ->update([
                        'pho_fin' => $endAt->format('Y-m-d H:i:s'),
                    ]);
            });

            return $this->find($id);
        }

        throw ValidationException::withMessages([
            'promotion' => 'Solo se pueden editar horarios de promociones pendientes o en proceso.',
        ]);
    }

    public function find(int $id): array
    {
        $promotion = $this->baseQuery()
            ->where('p.prm_id', $id)
            ->first();

        if (! $promotion) {
            throw ValidationException::withMessages([
                'promotion' => 'La promocion creada no pudo ser consultada.',
            ]);
        }

        return $this->normalizePromotion($promotion);
    }

    /**
     * @param array<int, array{code: string, discount: ?float, price: ?float}> $rows
     * @return array<int, array{productId: int, discount: ?float, price: ?float}>
     */
    private function resolveProducts(array $rows, int $countryId, string $promotionType): array
    {
        $codes = collect($rows)->pluck('code')->values()->all();
        $products = DB::table('stj_productos')
            ->whereIn('pro_codigo', $codes)
            ->pluck('pro_id', 'pro_codigo');

        $missing = collect($codes)
            ->reject(fn (string $code) => $products->has($code))
            ->values()
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'products' => 'Articulos no encontrados: '.implode(', ', array_slice($missing, 0, 10)),
            ]);
        }

        return collect($rows)
            ->map(function (array $row) use ($products, $countryId, $promotionType) {
                $price = $row['price'];

                if ($countryId === 5 && $promotionType === 'PUNTO-PRECIO' && $price !== null) {
                    $price = round($price * 1.07, 2);
                }

                return [
                    'productId' => (int) $products[$row['code']],
                    'discount' => $row['discount'],
                    'price' => $price,
                ];
            })
            ->unique('productId')
            ->values()
            ->all();
    }

    private function countries(): array
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
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        return [
            'origins' => ['TODO', 'WEB', 'APP'],
            'checkoutTypes' => ['TODO', 'D', 'T'],
            'types' => ['TODO', 'CATEGORIA', 'SUB-CATEGORIA', 'SKU', 'TARJETA', 'ENTREGA'],
            'promotionTypes' => ['DESCUENTO', 'DESCUENTO-SKU', 'PUNTO-PRECIO', 'CONDICION-SKU', 'CONDICION-ENTREGA', 'CONDICION-PAGO'],
            'restrictions' => ['TOTAL_COMPRA', '21/2', '2x1', '3x2', '2doPrecio', 'TARJETA', 'ENTREGA', '2xPP'],
        ];
    }

    /**
     * @return array{id: int, code: string}
     */
    private function resolveCountry(mixed $country): array
    {
        $country = trim((string) $country);
        $query = DB::table('stj_paises')->select(['pai_id', 'pai_codigo']);

        if ($country === '') {
            throw ValidationException::withMessages([
                'country' => 'Debe seleccionar un pais.',
            ]);
        }

        if (is_numeric($country)) {
            $resolved = $query->where('pai_id', (int) $country)->first();
        } else {
            $resolved = $query->where('pai_codigo', strtoupper($country))->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return [
            'id' => (int) $resolved->pai_id,
            'code' => strtoupper((string) $resolved->pai_codigo),
        ];
    }

    private function resolveCountryId(?string $country): ?int
    {
        $country = trim((string) $country);

        if ($country === '') {
            return null;
        }

        if (is_numeric($country)) {
            return (int) $country;
        }

        $resolved = DB::table('stj_paises')
            ->where('pai_codigo', strtoupper($country))
            ->value('pai_id');

        return $resolved !== null ? (int) $resolved : null;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function baseQuery()
    {
        $schedule = DB::table('stj_promociones_horario')
            ->selectRaw('pho_promocion, MIN(pho_inicio) as pho_inicio, MAX(pho_fin) as pho_fin, MAX(pho_estado) as pho_estado')
            ->where('pho_tipo', 'NORMAL')
            ->groupBy('pho_promocion');

        $products = DB::table('stj_promociones_producto')
            ->selectRaw('ppr_promocion, COUNT(*) as products_count')
            ->groupBy('ppr_promocion');

        $assets = DB::table('stj_assets')
            ->selectRaw('ast_idpromocion, COUNT(*) as assets_count')
            ->where('ast_tipo_accion', 1)
            ->groupBy('ast_idpromocion');

        return DB::table('stj_promociones as p')
            ->leftJoin('stj_paises as c', 'c.pai_id', '=', 'p.prm_pais')
            ->leftJoinSub($schedule, 'h', 'h.pho_promocion', '=', 'p.prm_id')
            ->leftJoinSub($products, 'pp', 'pp.ppr_promocion', '=', 'p.prm_id')
            ->leftJoinSub($assets, 'a', 'a.ast_idpromocion', '=', 'p.prm_id')
            ->select([
                'p.prm_id',
                'p.prm_ticket',
                'p.prm_pais',
                'p.prm_origen',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
                'p.prm_modalidad',
                'p.prm_tipo',
                'p.prm_estado',
                'p.prm_tipo_promocion',
                'p.prm_aplica',
                'p.prm_precio',
                'p.prm_porcentaje',
                'p.prm_restriccion',
                'p.prm_fecha',
                'p.prm_grid_promo',
                'p.prm_encabezado',
                'h.pho_inicio',
                'h.pho_fin',
                'h.pho_estado',
                'c.pai_codigo',
                'c.pai_nombre',
                DB::raw('COALESCE(pp.products_count, 0) as products_count'),
                DB::raw('COALESCE(a.assets_count, 0) as assets_count'),
            ]);
    }

    private function normalizePromotion(object $promotion): array
    {
        return [
            'id' => (int) $promotion->prm_id,
            'ticket' => $promotion->prm_ticket,
            'name' => trim((string) $promotion->prm_nombre),
            'commercialName' => trim((string) $promotion->prm_nombre_comercial),
            'origin' => $promotion->prm_origen,
            'modality' => $promotion->prm_modalidad,
            'type' => $promotion->prm_tipo,
            'status' => $promotion->prm_estado,
            'promotionType' => $promotion->prm_tipo_promocion,
            'appliesTo' => $promotion->prm_aplica,
            'price' => $promotion->prm_precio !== null ? (float) $promotion->prm_precio : null,
            'percentage' => $promotion->prm_porcentaje !== null ? (float) $promotion->prm_porcentaje : null,
            'restriction' => $promotion->prm_restriccion,
            'gridPromo' => $promotion->prm_grid_promo,
            'header' => $promotion->prm_encabezado,
            'createdAt' => $promotion->prm_fecha,
            'startAt' => $promotion->pho_inicio,
            'endAt' => $promotion->pho_fin,
            'scheduleStatus' => $promotion->pho_estado,
            'productsCount' => (int) $promotion->products_count,
            'assetsCount' => (int) $promotion->assets_count,
            'link' => "Promociones/?idPromocion={$promotion->prm_id}&Promo",
            'country' => [
                'id' => $promotion->prm_pais !== null ? (int) $promotion->prm_pais : null,
                'code' => strtoupper((string) $promotion->pai_codigo),
                'name' => trim((string) $promotion->pai_nombre),
            ],
        ];
    }
}
