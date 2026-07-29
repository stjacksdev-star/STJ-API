<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PromotionService
{
    private const DASHBOARD_TIMEZONE = 'America/El_Salvador';

    public function __construct(
        private readonly PromotionProductImportService $imports,
        private readonly PromotionHistoryService $history,
    ) {}

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
                'p.prm_tipo_checkout',
                'p.prm_alcance_tienda',
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

        $promotions = $query->get();
        $stores = $this->storesByPromotionIds(
            $promotions->pluck('prm_id')->map(fn ($id) => (int) $id)->all()
        );

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
            'promotions' => $promotions
                ->map(fn ($promotion) => $this->normalizePromotion(
                    $promotion,
                    $stores[(int) $promotion->prm_id] ?? [],
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $products = null, array $actor = []): array
    {
        $country = $this->resolveCountry($data['country'] ?? null);
        $startAt = $this->dashboardDateTime($data['startAt']);
        $endAt = $this->dashboardDateTime($data['endAt']);
        $type = (string) $data['type'];
        $promotionType = (string) $data['promotionType'];
        $storeScope = $this->stringOrNull($data['storeScope'] ?? null);
        $storeIds = $data['stores'] ?? [];
        $productRows = [];

        if ($startAt->lessThanOrEqualTo($this->dashboardNow())) {
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

        $id = DB::transaction(function () use ($data, $country, $startAt, $endAt, $type, $productRows, $storeScope, $storeIds, $actor) {
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
                'prm_alcance_tienda' => $storeScope,
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

            $this->replacePromotionStores($promotionId, $country['id'], $storeScope, $storeIds);

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

            $this->history->record($promotionId, 'GENERAL', 'Promocion creada desde Dashboard.', $actor);

            if ($productRows !== []) {
                $this->history->record(
                    $promotionId,
                    'PRODUCTOS',
                    count($productRows).' producto(s) asociados al crear la promocion.',
                    $actor,
                );
            }

            return $promotionId;
        });

        return $this->find($id);
    }

    /**
     * @param  array<int, mixed>  $storeIds
     * @param  array<string, mixed>  $actor
     */
    public function updateStores(int $id, ?string $storeScope, array $storeIds, array $actor = []): array
    {
        DB::transaction(function () use ($id, $storeScope, $storeIds, $actor) {
            $promotion = DB::table('stj_promociones')
                ->where('prm_id', $id)
                ->lockForUpdate()
                ->first();

            if (! $promotion) {
                throw ValidationException::withMessages([
                    'promotion' => 'La promocion seleccionada no existe.',
                ]);
            }

            if ((string) $promotion->prm_estado !== 'PENDIENTE') {
                throw ValidationException::withMessages([
                    'promotion' => 'Las tiendas solo pueden modificarse en promociones PENDIENTE.',
                ]);
            }

            if ((string) $promotion->prm_tipo_checkout === 'D' && $storeScope !== null) {
                throw ValidationException::withMessages([
                    'storeScope' => 'Las promociones solo domicilio no manejan alcance de tiendas.',
                ]);
            }

            DB::table('stj_promociones')
                ->where('prm_id', $id)
                ->update(['prm_alcance_tienda' => $storeScope]);

            $this->replacePromotionStores($id, (int) $promotion->prm_pais, $storeScope, $storeIds);
            $this->history->record(
                $id,
                'INFORMACION',
                'Alcance de tiendas actualizado a '.($storeScope ?? 'NULL').'.',
                $actor,
            );
        });

        return $this->find($id);
    }

    public function updateSchedule(int $id, array $data, array $actor = []): array
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
                $newName = $promotionUpdates['prm_nombre_comercial'];
                $oldName = $this->stringOrNull($promotion->prm_nombre_comercial);

                if ($newName !== $oldName) {
                    DB::transaction(function () use ($id, $promotionUpdates, $oldName, $newName, $actor) {
                        DB::table('stj_promociones')->where('prm_id', $id)->update($promotionUpdates);
                        $this->history->record($id, 'INFORMACION', $this->commercialNameDescription($oldName, $newName), $actor);
                    });
                }
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
        $startAt = $this->dashboardDateTime($data['startAt'] ?? $schedule->pho_inicio);
        $endAt = $this->dashboardDateTime($data['endAt'] ?? $schedule->pho_fin);
        $syncAssetsEndAt = array_key_exists('endAt', $data);

        if ($status === 'PENDIENTE') {
            if ($startAt->lessThanOrEqualTo($this->dashboardNow())) {
                throw ValidationException::withMessages([
                    'startAt' => 'La fecha inicial no puede ser menor o igual a la fecha y hora actual.',
                ]);
            }

            if ($startAt->greaterThanOrEqualTo($endAt)) {
                throw ValidationException::withMessages([
                    'endAt' => 'La fecha inicial es mayor a la fecha final.',
                ]);
            }

            DB::transaction(function () use ($id, $schedule, $startAt, $endAt, $promotionUpdates, $syncAssetsEndAt, $promotion, $actor) {
                $nameChanged = array_key_exists('prm_nombre_comercial', $promotionUpdates)
                    && $promotionUpdates['prm_nombre_comercial'] !== $this->stringOrNull($promotion->prm_nombre_comercial);
                $scheduleChanged = $startAt->format('Y-m-d H:i:s') !== (string) $schedule->pho_inicio
                    || $endAt->format('Y-m-d H:i:s') !== (string) $schedule->pho_fin;
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

                if ($syncAssetsEndAt) {
                    $this->syncPromotionAssetsEndAt($id, $endAt);
                }

                if ($nameChanged) {
                    $this->history->record($id, 'INFORMACION', $this->commercialNameDescription($this->stringOrNull($promotion->prm_nombre_comercial), $promotionUpdates['prm_nombre_comercial']), $actor);
                }
                if ($scheduleChanged) {
                    $this->history->record($id, 'HORARIO', "Horario actualizado: {$startAt->format('Y-m-d H:i:s')} a {$endAt->format('Y-m-d H:i:s')}.", $actor);
                }
            });

            return $this->find($id);
        }

        if ($status === 'EN-PROCESO') {
            $currentStart = $this->dashboardDateTime($schedule->pho_inicio);

            if ($endAt->lessThanOrEqualTo($this->dashboardNow())) {
                throw ValidationException::withMessages([
                    'endAt' => 'La fecha final debe ser mayor a la fecha y hora actual.',
                ]);
            }

            if ($endAt->lessThanOrEqualTo($currentStart)) {
                throw ValidationException::withMessages([
                    'endAt' => 'La fecha final debe ser mayor a la fecha inicial.',
                ]);
            }

            DB::transaction(function () use ($id, $schedule, $endAt, $promotionUpdates, $syncAssetsEndAt, $promotion, $actor) {
                $nameChanged = array_key_exists('prm_nombre_comercial', $promotionUpdates)
                    && $promotionUpdates['prm_nombre_comercial'] !== $this->stringOrNull($promotion->prm_nombre_comercial);
                $scheduleChanged = $endAt->format('Y-m-d H:i:s') !== (string) $schedule->pho_fin;
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

                if ($syncAssetsEndAt) {
                    $this->syncPromotionAssetsEndAt($id, $endAt);
                }

                if ($nameChanged) {
                    $this->history->record($id, 'INFORMACION', $this->commercialNameDescription($this->stringOrNull($promotion->prm_nombre_comercial), $promotionUpdates['prm_nombre_comercial']), $actor);
                }
                if ($scheduleChanged) {
                    $this->history->record($id, 'HORARIO', "Fecha final actualizada a {$endAt->format('Y-m-d H:i:s')}.", $actor);
                }
            });

            return $this->find($id);
        }

        throw ValidationException::withMessages([
            'promotion' => 'Solo se pueden editar horarios de promociones pendientes o en proceso.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $actor
     */
    public function replaceProducts(int $id, UploadedFile $products, array $actor = []): array
    {
        $promotion = DB::table('stj_promociones')->where('prm_id', $id)->first();

        if (! $promotion) {
            throw ValidationException::withMessages(['promotion' => 'La promocion seleccionada no existe.']);
        }

        if ((string) $promotion->prm_estado !== 'EN-PROCESO') {
            throw ValidationException::withMessages([
                'promotion' => 'Los productos solo pueden reemplazarse en promociones EN-PROCESO.',
            ]);
        }

        if ((string) $promotion->prm_tipo === 'TODO') {
            throw ValidationException::withMessages([
                'promotion' => 'Las promociones tipo TODO aplican a todo el pais y no manejan productos asociados.',
            ]);
        }

        $productRows = $this->resolveProducts(
            $this->imports->read($products),
            (int) $promotion->prm_pais,
            (string) $promotion->prm_tipo_promocion,
        );

        try {
            $result = DB::transaction(function () use ($id, $productRows, $actor) {
                $lockedPromotion = DB::table('stj_promociones')
                    ->where('prm_id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedPromotion || (string) $lockedPromotion->prm_estado !== 'EN-PROCESO') {
                    throw new RuntimeException('La promocion dejo de estar disponible para edicion.');
                }

                if ((string) $lockedPromotion->prm_tipo === 'TODO') {
                    throw new RuntimeException('Las promociones tipo TODO no manejan productos asociados.');
                }

                $previousCount = DB::table('stj_promociones_producto')
                    ->where('ppr_promocion', $id)
                    ->count();

                $this->resetPromotionProductFields($lockedPromotion);

                if ((string) $lockedPromotion->prm_tipo !== 'TODO') {
                    DB::table('stj_promociones_producto')
                        ->where('ppr_promocion', $id)
                        ->delete();
                }

                if (DB::table('stj_promociones_producto')->where('ppr_promocion', $id)->exists()) {
                    throw new RuntimeException('No fue posible eliminar todos los productos actuales de la promocion.');
                }

                DB::table('stj_promociones_producto')->insert(
                    array_map(fn (array $row) => [
                        'ppr_promocion' => $id,
                        'ppr_producto' => $row['productId'],
                        'ppr_descuento' => $row['discount'],
                        'ppr_precio' => $row['price'],
                    ], $productRows),
                );

                $insertedCount = DB::table('stj_promociones_producto')
                    ->where('ppr_promocion', $id)
                    ->count();

                if ($insertedCount !== count($productRows)) {
                    throw new RuntimeException('El conteo de productos insertados no coincide con el Excel.');
                }

                $this->activatePromotionProductFields($lockedPromotion);
                $this->history->record(
                    $id,
                    'PRODUCTOS',
                    "Productos reemplazados desde Excel: {$previousCount} eliminados y {$insertedCount} insertados.",
                    $actor,
                );

                return ['previousCount' => $previousCount, 'insertedCount' => $insertedCount];
            });
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'products' => 'No fue posible completar el reemplazo. Todos los cambios fueron revertidos.',
            ]);
        }

        return [...$result, 'promotion' => $this->find($id)];
    }

    private function resetPromotionProductFields(object $promotion): void
    {
        $query = DB::table('stj_producto_pais')->where('ppa_pais', (int) $promotion->prm_pais);

        if ((string) $promotion->prm_tipo !== 'TODO') {
            $productIds = DB::table('stj_promociones_producto')
                ->where('ppr_promocion', (int) $promotion->prm_id)
                ->select('ppr_producto');
            $query->whereIn('ppa_producto', $productIds);
        }

        $query->update([
            'ppa_origen_descuento' => null,
            'ppa_tipo_descuento' => null,
            'ppa_descuento' => null,
            'ppa_precio_tienda' => null,
            'ppa_promo_logo' => null,
            'ppa_promo_nombre' => null,
        ]);
    }

    private function activatePromotionProductFields(object $promotion): void
    {
        $promotionId = (int) $promotion->prm_id;
        $countryId = (int) $promotion->prm_pais;
        $origin = (string) $promotion->prm_origen;
        $symbol = match ($countryId) {
            1, 5 => '$',
            2 => 'Q',
            3 => 'C',
            7 => 'L',
            default => '',
        };

        if ((string) $promotion->prm_tipo_promocion === 'DESCUENTO') {
            $discount = (float) $promotion->prm_porcentaje;
            DB::table('stj_producto_pais')->where('ppa_pais', $countryId)->update([
                'ppa_origen_descuento' => $origin,
                'ppa_tipo_descuento' => $promotion->prm_aplica,
                'ppa_descuento' => $discount,
                'ppa_promo_nombre' => round($discount).'% DE DESCUENTO',
            ]);

            return;
        }

        $query = DB::table('stj_producto_pais as pp')
            ->join('stj_promociones_producto as pr', 'pr.ppr_producto', '=', 'pp.ppa_producto')
            ->where('pr.ppr_promocion', $promotionId)
            ->where('pp.ppa_pais', $countryId);

        if ((string) $promotion->prm_tipo_promocion === 'DESCUENTO-SKU') {
            if ((float) $promotion->prm_porcentaje > 0) {
                $discount = (float) $promotion->prm_porcentaje;
                $query->update([
                    'ppa_origen_descuento' => $origin,
                    'ppa_tipo_descuento' => $promotion->prm_aplica,
                    'ppa_descuento' => $discount,
                    'ppa_promo_nombre' => round($discount).'% DE DESCUENTO',
                ]);
            } else {
                $query->update([
                    'ppa_origen_descuento' => $origin,
                    'ppa_tipo_descuento' => $promotion->prm_aplica,
                    'ppa_descuento' => DB::raw('pr.ppr_descuento'),
                    'ppa_promo_nombre' => DB::raw("CONCAT(ROUND(pr.ppr_descuento, 0), '% DE DESCUENTO')"),
                ]);
            }

            return;
        }

        if ((string) $promotion->prm_tipo_promocion === 'PUNTO-PRECIO') {
            $price = $promotion->prm_precio !== null ? (float) $promotion->prm_precio : 0.0;
            $priceExpression = $price > 0
                ? ($countryId === 5 ? round($price / 1.07) : $price)
                : DB::raw($countryId === 5 ? 'ROUND(pr.ppr_precio / 1.07, 0)' : 'pr.ppr_precio');
            $nameExpression = $price > 0
                ? 'Llevatelo a '.$symbol.round((float) $priceExpression)
                : DB::raw("CONCAT('Llevatelo a {$symbol}', ROUND(".($countryId === 5 ? 'pr.ppr_precio / 1.07' : 'pr.ppr_precio').', 0))');
            $query->update([
                'ppa_origen_descuento' => $origin,
                'ppa_tipo_descuento' => 'PRECIO_TODO',
                'ppa_precio_tienda' => $priceExpression,
                'ppa_promo_nombre' => $nameExpression,
            ]);

            return;
        }

        if ((string) $promotion->prm_tipo_promocion !== 'CONDICION-SKU') {
            return;
        }

        $restriction = (string) $promotion->prm_restriccion;
        $name = match ($restriction) {
            '2xPP' => 'Aplica 2x'.$symbol.round((float) $promotion->prm_precio),
            '21/2' => '2da prenda con el 50% desc',
            '2doPrecio' => 'Aplica 2da Prenda a '.$symbol.round((float) $promotion->prm_precio),
            '2x1' => 'Promocion 2x1',
            default => null,
        };

        if ($name !== null) {
            $query->update([
                'ppa_origen_descuento' => $restriction === '2x1' ? 'TODO' : $origin,
                'ppa_tipo_descuento' => $restriction === '2x1' ? 'TODO' : 'PRECIO_TODO',
                'ppa_descuento' => $restriction === '2x1' ? null : DB::raw('pp.ppa_descuento'),
                'ppa_promo_nombre' => $name,
            ]);
        }
    }

    private function dashboardDateTime(mixed $value): Carbon
    {
        return Carbon::parse((string) $value, self::DASHBOARD_TIMEZONE);
    }

    private function dashboardNow(): Carbon
    {
        return Carbon::now(self::DASHBOARD_TIMEZONE);
    }

    private function syncPromotionAssetsEndAt(int $promotionId, Carbon $endAt): void
    {
        DB::table('stj_assets')
            ->where('ast_tipo_accion', 1)
            ->where('ast_idpromocion', $promotionId)
            ->update([
                'ast_fin' => $endAt->format('Y-m-d H:i:s'),
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

        $stores = $this->storesByPromotionIds([$id]);

        return $this->normalizePromotion($promotion, $stores[$id] ?? []);
    }

    public function eligibleStores(string $country): array
    {
        $resolved = $this->resolveCountry($country);

        return DB::table('stj_tiendas')
            ->where('tie_pais', $resolved['id'])
            ->where('tie_productos', 1)
            ->orderBy('tie_nombre')
            ->get(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->map(fn ($store) => [
                'id' => (int) $store->tie_id,
                'code' => (string) $store->tie_codigo,
                'name' => trim((string) $store->tie_nombre),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{code: string, discount: ?float, price: ?float}>  $rows
     * @return array<int, array{productId: int, discount: ?float, price: ?float}>
     */
    private function resolveProducts(array $rows, int $countryId, string $promotionType): array
    {
        $codes = collect($rows)->pluck('code')->values()->all();
        $products = DB::table('stj_productos')
            ->whereIn('pro_codigo', $codes)
            ->pluck('pro_id', 'pro_codigo');

        return collect($rows)
            ->filter(fn (array $row) => $products->has($row['code']))
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
            'storeScopes' => ['TODAS', 'SELECCIONADAS'],
            'types' => ['TODO', 'SKU'],
            'promotionTypes' => ['DESCUENTO', 'CONDICION-SKU', 'PUNTO-PRECIO', 'DESCUENTO-SKU'],
            'restrictions' => ['21/2', '2x1', '2doPrecio', '2xPP'],
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

    private function commercialNameDescription(?string $oldName, ?string $newName): string
    {
        return sprintf('Titulo comercial actualizado de "%s" a "%s".', $oldName ?? '', $newName ?? '');
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
                'p.prm_tipo_checkout',
                'p.prm_alcance_tienda',
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

    /**
     * @param  array<int, array<string, mixed>>  $stores
     */
    private function normalizePromotion(object $promotion, array $stores = []): array
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
            'checkoutType' => $promotion->prm_tipo_checkout,
            'storeScope' => $promotion->prm_alcance_tienda,
            'tiendas' => $stores,
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

    /**
     * @param  array<int, mixed>  $storeIds
     */
    private function replacePromotionStores(
        int $promotionId,
        int $countryId,
        ?string $storeScope,
        array $storeIds,
    ): void {
        DB::table('stj_promociones_tienda')
            ->where('prt_promocion', $promotionId)
            ->delete();

        if ($storeScope !== 'SELECCIONADAS') {
            return;
        }

        $ids = array_map(fn ($id) => (int) $id, $storeIds);

        if ($ids === []) {
            throw ValidationException::withMessages([
                'stores' => 'Debe seleccionar al menos una tienda.',
            ]);
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'stores' => 'No se permiten tiendas duplicadas.',
            ]);
        }

        $stores = DB::table('stj_tiendas')
            ->whereIn('tie_id', $ids)
            ->get(['tie_id', 'tie_pais', 'tie_productos']);

        if ($stores->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'stores' => 'Una o mas tiendas seleccionadas no existen.',
            ]);
        }

        if ($stores->contains(fn ($store) => (int) $store->tie_pais !== $countryId)) {
            throw ValidationException::withMessages([
                'stores' => 'Todas las tiendas deben pertenecer al pais de la promocion.',
            ]);
        }

        if ($stores->contains(fn ($store) => (int) $store->tie_productos !== 1)) {
            throw ValidationException::withMessages([
                'stores' => 'Todas las tiendas deben tener productos habilitados.',
            ]);
        }

        DB::table('stj_promociones_tienda')->insert(
            array_map(fn (int $storeId) => [
                'prt_promocion' => $promotionId,
                'prt_tienda' => $storeId,
                'prt_fecha_creacion' => now(),
            ], $ids),
        );
    }

    /**
     * @param  array<int, int>  $promotionIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function storesByPromotionIds(array $promotionIds): array
    {
        if ($promotionIds === []) {
            return [];
        }

        return DB::table('stj_promociones_tienda as pt')
            ->join('stj_tiendas as t', 't.tie_id', '=', 'pt.prt_tienda')
            ->whereIn('pt.prt_promocion', $promotionIds)
            ->orderBy('t.tie_nombre')
            ->get([
                'pt.prt_promocion',
                't.tie_id',
                't.tie_codigo',
                't.tie_nombre',
                't.tie_pais',
                't.tie_productos',
            ])
            ->groupBy('prt_promocion')
            ->map(fn ($stores) => $stores->map(fn ($store) => [
                'id' => (int) $store->tie_id,
                'code' => (string) $store->tie_codigo,
                'name' => trim((string) $store->tie_nombre),
                'countryId' => (int) $store->tie_pais,
                'productsEnabled' => (int) $store->tie_productos === 1,
            ])->values()->all())
            ->all();
    }
}
