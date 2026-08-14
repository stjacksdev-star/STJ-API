<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function __construct(private readonly ProductCodeImportService $imports) {}

    public function catalogs(?string $country): array
    {
        $countryId = DB::table('stj_paises')->where('pai_codigo', strtoupper((string) $country))->value('pai_id');
        return [
            'categories' => DB::table('stj_categorias')->orderBy('cat_nombre')->get(['cat_id as id', 'cat_nombre as name']),
            'collections' => DB::table('stj_coleccion')->when($countryId, fn ($q) => $q->where('col_pais', $countryId))->orderByDesc('col_id')->get(['col_id as id', 'col_nombre as name']),
            'automaticTemplates' => DB::table('stj_cupones_header')->whereNotNull('che_config_automatica')->distinct()->orderBy('che_config_automatica')->pluck('che_config_automatica'),
        ];
    }

    public function index(?string $country, ?string $status, ?string $search = null, int $page = 1, int $perPage = 20): array
    {
        $countries = DB::table('stj_paises')->orderBy('pai_id')->get(['pai_id', 'pai_codigo', 'pai_nombre']);
        $term = $search ? '%'.trim($search).'%' : null;
        $matchingCodeHeaders = $term
            ? DB::table('stj_cupones')->where('cup_codigo', 'like', $term)->distinct()->pluck('cup_header')->all()
            : [];
        $base = DB::table('stj_cupones_header as h')
            ->leftJoin('stj_paises as p', 'p.pai_id', '=', 'h.che_pais')
            ->when($country, fn ($q) => $q->where('p.pai_codigo', strtoupper($country)))
            ->when($status, fn ($q) => $q->where('h.che_estado', strtoupper($status)))
            ->when($term, function ($q) use ($term, $matchingCodeHeaders) {
                $q->where(function ($nested) use ($term, $matchingCodeHeaders) {
                    $nested->where('h.che_nombre', 'like', $term)
                        ->orWhere('h.che_nombre_comercial', 'like', $term)
                        ->orWhere('h.che_id', trim($term, '%'));
                    if ($matchingCodeHeaders !== []) $nested->orWhereIn('h.che_id', $matchingCodeHeaders);
                });
            });
        $total = (clone $base)->count('h.che_id');
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $headers = $base
            ->orderByDesc('h.che_id')
            ->forPage($page, $perPage)
            ->select(['h.*', 'p.pai_codigo', 'p.pai_nombre'])
            ->get();
        $details = DB::table('stj_cupones')->whereIn('cup_header', $headers->pluck('che_id'))
            ->groupBy('cup_header')->get(['cup_header', DB::raw('MIN(cup_codigo) as generic_code'), DB::raw('COUNT(*) as codes_count')])
            ->keyBy('cup_header');

        $headers->each(function ($header) use ($details) {
            $detail = $details->get($header->che_id);
            $header->generic_code = $detail?->generic_code;
            $header->codes_count = $detail?->codes_count ?? 0;
        });

        return [
            'countries' => $countries->map(fn ($p) => ['id' => (int) $p->pai_id, 'code' => $p->pai_codigo, 'name' => $p->pai_nombre])->all(),
            'coupons' => $headers->map(fn ($row) => $this->payload($row))->all(),
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage],
        ];
    }

    public function save(array $data, ?int $id = null, ?UploadedFile $productsFile = null, ?UploadedFile $customersFile = null): array
    {
        if (($data['audience'] ?? 'NA') !== 'NA' && $data['generic'] === 'SI') {
            throw ValidationException::withMessages(['generic' => 'Los cupones para VIP o clientes por archivo deben ser personales, no genéricos.']);
        }
        if (! empty($data['automaticTemplate']) && ($data['generic'] === 'SI' || ($data['audience'] ?? 'NA') !== 'NA')) {
            throw ValidationException::withMessages(['automaticTemplate' => 'Una plantilla automática solo define reglas; no puede generar códigos al guardarse.']);
        }

        return DB::transaction(function () use ($data, $id, $productsFile, $customersFile) {
            $isNew = $id === null;
            $country = DB::table('stj_paises')->where('pai_codigo', strtoupper($data['country']))->first(['pai_id']);
            if (! $country) throw ValidationException::withMessages(['country' => 'El país seleccionado no existe.']);

            $header = [
                'che_nombre' => trim($data['name']),
                'che_nombre_comercial' => trim($data['commercialName'] ?? ''),
                'che_pais' => $country->pai_id,
                'che_aplica' => $data['channel'],
                'che_tipo' => $data['type'],
                'che_checkout' => $data['checkout'],
                'che_generico' => $data['generic'],
                'che_inicio' => $data['startAt'],
                'che_final' => $data['endAt'],
                'che_monto' => $data['type'] === 'PRECIO' ? $data['amount'] : 0,
                'che_descuento' => $data['type'] === 'DESCUENTO' ? $data['discount'] : 0,
                'che_aplica_monto_minimo' => $data['minimumEnabled'],
                'che_monto_minimo' => $data['minimumEnabled'] === 'SI' ? $data['minimumAmount'] : 0,
                'che_descuento_extra' => $data['extraDiscount'],
                'che_multiple' => $data['multiple'],
                'che_aplica_promo' => $data['promotionRule'],
                'che_solo_primera_compra' => $data['firstPurchaseOnly'],
                'che_tipo_productos' => 'NA',
                'che_para' => $data['audience'] ?? 'NA',
                'che_config_automatica' => ($data['automaticTemplate'] ?? '') !== '' ? strtoupper(trim($data['automaticTemplate'])) : null,
                'che_genero' => ($data['productScope'] ?? 'NA') === 'GEN' ? ($data['categoryId'] ?? null) : null,
                'che_coleccion' => ($data['productScope'] ?? 'NA') === 'COL' ? ($data['collectionId'] ?? null) : null,
                'che_estado' => $data['status'],
            ];
            $header['che_tipo_productos'] = $data['productScope'] ?? 'NA';

            if ($id) {
                $exists = DB::table('stj_cupones_header')->where('che_id', $id)->lockForUpdate()->exists();
                if (! $exists) throw ValidationException::withMessages(['coupon' => 'El cupón no existe.']);
                DB::table('stj_cupones_header')->where('che_id', $id)->update($header);
            } else {
                $id = DB::table('stj_cupones_header')->insertGetId($header);
            }

            if ($data['generic'] === 'SI') {
                $code = strtoupper(trim($data['code'] ?? ''));
                $duplicate = DB::table('stj_cupones')->where('cup_codigo', $code)->when($id, fn ($q) => $q->where('cup_header', '<>', $id))->exists();
                if ($duplicate) throw ValidationException::withMessages(['code' => 'Este código ya pertenece a otro cupón.']);
                $detail = [
                    'cup_codigo' => $code, 'cup_estado' => $data['status'],
                    'cup_monto' => $header['che_monto'], 'cup_descuento' => $header['che_descuento'],
                    'cup_multiple' => $data['multiple'],
                ];
                $detailId = DB::table('stj_cupones')->where('cup_header', $id)->orderBy('cup_id')->value('cup_id');
                $detailId ? DB::table('stj_cupones')->where('cup_id', $detailId)->update($detail) : DB::table('stj_cupones')->insert($detail + ['cup_header' => $id]);
            }

            if (! empty($data['automaticTemplate'])) {
                DB::table('stj_cupones')->where('cup_header', $id)->delete();
            } elseif (($data['audience'] ?? 'NA') === 'VIP' && $isNew) {
                $emails = DB::table('stj_usuarios')->where('usu_vip', 'SI')->where('usu_activo', 1)->where('usu_pais_registro', $country->pai_id)->pluck('usu_correo')->all();
                $this->createPersonalCoupons($id, $emails, $header, $data);
            } elseif (($data['audience'] ?? 'NA') === 'PLA' && $customersFile) {
                $this->createPersonalCoupons($id, $this->imports->read($customersFile), $header, $data);
            }

            if (($data['productScope'] ?? 'NA') !== 'NA' && ($productsFile || $isNew || in_array($data['productScope'], ['GEN', 'COL'], true))) {
                $codes = match ($data['productScope']) {
                    'PLA' => $this->imports->read($productsFile),
                    'GEN' => DB::table('stj_productos as p')->join('stj_producto_pais as pp', function ($j) use ($country) { $j->on('pp.ppa_producto', 'p.pro_id')->where('pp.ppa_pais', $country->pai_id); })->where('p.pro_categoria', $data['categoryId'])->pluck('p.pro_codigo')->all(),
                    'COL' => explode(',', (string) DB::table('stj_coleccion')->where('col_id', $data['collectionId'])->where('col_pais', $country->pai_id)->value('col_codigos')),
                    default => [],
                };
                $products = DB::table('stj_productos')->whereIn('pro_codigo', collect($codes)->map(fn ($v) => trim((string) $v))->filter())->pluck('pro_id');
                DB::table('stj_cupones_producto')->where('cpr_cupon', $id)->delete();
                foreach ($products as $productId) DB::table('stj_cupones_producto')->insert(['cpr_cupon' => $id, 'cpr_producto' => $productId, 'cpr_descuento' => $header['che_descuento'], 'cpr_precio' => $header['che_monto']]);
            }

            $row = DB::table('stj_cupones_header as h')->leftJoin('stj_paises as p', 'p.pai_id', '=', 'h.che_pais')
                ->leftJoin('stj_cupones as c', 'c.cup_header', '=', 'h.che_id')->where('h.che_id', $id)
                ->first(['h.*', 'p.pai_codigo', 'p.pai_nombre', 'c.cup_codigo as generic_code', DB::raw('1 as codes_count')]);
            return $this->payload($row);
        });
    }

    private function createPersonalCoupons(int $headerId, array $emails, array $header, array $data): void
    {
        foreach (collect($emails)->map(fn ($e) => strtolower(trim((string) $e)))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique() as $email) {
            if (DB::table('stj_cupones')->where('cup_header', $headerId)->whereRaw('LOWER(cup_correo) = ?', [$email])->exists()) continue;
            do { $code = strtoupper(bin2hex(random_bytes(3))); } while (DB::table('stj_cupones')->where('cup_codigo', $code)->exists());
            DB::table('stj_cupones')->insert(['cup_header' => $headerId, 'cup_codigo' => $code, 'cup_estado' => $data['status'], 'cup_monto' => $header['che_monto'], 'cup_descuento' => $header['che_descuento'], 'cup_multiple' => $data['multiple'], 'cup_correo' => $email]);
        }
    }

    private function payload(object $r): array
    {
        return [
            'id' => (int) $r->che_id, 'name' => $r->che_nombre, 'commercialName' => $r->che_nombre_comercial,
            'country' => ['id' => (int) $r->che_pais, 'code' => $r->pai_codigo, 'name' => $r->pai_nombre],
            'channel' => $r->che_aplica, 'type' => $r->che_tipo, 'checkout' => $r->che_checkout,
            'generic' => $r->che_generico, 'code' => $r->generic_code, 'codesCount' => (int) $r->codes_count,
            'amount' => (float) $r->che_monto, 'discount' => (float) $r->che_descuento,
            'minimumEnabled' => $r->che_aplica_monto_minimo, 'minimumAmount' => (float) $r->che_monto_minimo,
            'extraDiscount' => $r->che_descuento_extra, 'multiple' => $r->che_multiple,
            'promotionRule' => $r->che_aplica_promo, 'firstPurchaseOnly' => $r->che_solo_primera_compra,
            'startAt' => $r->che_inicio, 'endAt' => $r->che_final, 'status' => $r->che_estado,
            'automaticTemplate' => $r->che_config_automatica,
            'audience' => $r->che_para ?? 'NA', 'productScope' => $r->che_tipo_productos ?? 'NA',
            'categoryId' => $r->che_genero ? (int) $r->che_genero : null, 'collectionId' => $r->che_coleccion ? (int) $r->che_coleccion : null,
        ];
    }
}
