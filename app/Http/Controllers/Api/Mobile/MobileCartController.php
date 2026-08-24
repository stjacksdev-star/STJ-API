<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\CartOperationConflict;
use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontCartService;
use App\Services\StorefrontCartCouponService;
use App\Services\StorefrontShippingService;
use App\Services\StorefrontOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileCartController extends Controller
{
    public function __construct(
        private StorefrontCartService $carts,
        private StorefrontCartCouponService $coupons,
        private StorefrontShippingService $shipping,
        private StorefrontOrderService $orders,
    ) {}

    public function show(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);

        return response()->json($this->legacy(
            $this->cartInCurrentStore($request, $country, $visitor, $customer),
            $request->boolean('selected'),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $data = $request->validate([
            'producto' => ['required', 'integer', 'min:1'],
            'talla' => ['required', 'string', 'max:10'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:99'],
            'idUser' => ['nullable'],
            'idSesion' => ['nullable'],
            'tipo' => ['nullable', 'string'],
        ]);
        $product = DB::table('stj_productos')->where('pro_id', $data['producto'])->first(['pro_id', 'pro_codigo']);
        if (! $product) {
            throw ValidationException::withMessages(['producto' => 'Producto no encontrado.']);
        }

        try {
            $this->cartInCurrentStore($request, $country, $visitor, $customer);
            $result = $this->carts->add(strtolower($country->pai_codigo), $visitor, $customer, [
                'operation_uuid' => (string) Str::uuid(),
                'product_id' => (int) $product->pro_id,
                'sku' => (string) $product->pro_codigo,
                'size' => trim((string) $data['talla']),
                'quantity' => (int) $data['cantidad'],
            ]);

            return response()->json($this->legacy($result), 201);
        } catch (CartOperationConflict $exception) {
            return response()->json(['resultado' => false, 'mensaje' => $exception->getMessage()], 409);
        }
    }

    public function update(Request $request, int $item): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $data = $request->validate([
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:99'],
            'eliminar' => ['nullable', 'boolean'],
            'idUser' => ['nullable'],
            'idSesion' => ['nullable'],
        ]);
        $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $operation = ['operation_uuid' => (string) Str::uuid()];
        $result = ($data['eliminar'] ?? false)
            ? $this->carts->remove(strtolower($country->pai_codigo), $item, $visitor, $customer, $operation)
            : $this->carts->update(strtolower($country->pai_codigo), $item, $visitor, $customer, $operation + ['quantity' => (int) ($data['cantidad'] ?? 1)]);

        return response()->json($this->legacy($result));
    }

    public function select(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $data = $request->validate(['items' => ['present', 'array', 'max:100'], 'items.*' => ['integer', 'min:1']]);
        $result = $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $selected = collect($data['items'])->map(fn ($id) => (int) $id)->unique();

        foreach (data_get($result, 'cart.items', []) as $line) {
            $shouldSelect = $selected->contains((int) $line['id']);
            if ((bool) $line['selected'] === $shouldSelect) {
                continue;
            }
            $result = $this->carts->update(strtolower($country->pai_codigo), (int) $line['id'], $visitor, $customer, [
                'operation_uuid' => (string) Str::uuid(),
                'selected' => $shouldSelect,
            ]);
        }

        return response()->json($this->legacy($result, true));
    }

    public function quoteShipping(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $data = $request->validate(['municipioId' => ['nullable', 'integer', 'min:1']]);
        $cart = $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $type = (string) data_get($cart, 'cart.type', 'DOMICILIO');
        if ($type === 'DOMICILIO') {
            $validCity = isset($data['municipioId']) && DB::table('stj_world_cities as city')
                ->join('stj_world_states as state', 'state.id', '=', 'city.state_id')
                ->where('city.id', (int) $data['municipioId'])
                ->where('state.country_id', $country->pai_id_world)
                ->exists();
            if (! $validCity) {
                throw ValidationException::withMessages(['municipioId' => 'El municipio no pertenece al pais seleccionado.']);
            }
        }
        $subtotal = number_format((float) data_get($cart, 'cart.totals.total', 0), 2, '.', '');
        $quote = $this->shipping->quote(
            $country,
            $type,
            isset($data['municipioId']) ? (int) $data['municipioId'] : null,
            $subtotal,
        );
        $couponResolution = $this->coupons->revalidateForIdentity(
            strtolower((string) $country->pai_codigo),
            $visitor,
            $customer,
            (string) $customer->usu_correo,
            (float) $quote['shipping_amount'],
        );
        $shippingDiscount = min(
            (float) $quote['shipping_amount'],
            (float) data_get($couponResolution, 'totals.shippingDiscount', 0),
        );
        if ($shippingDiscount > 0) {
            $quote['shipping_amount'] = number_format(max(0, (float) $quote['shipping_amount'] - $shippingDiscount), 2, '.', '');
            $quote['display_amount'] = (float) $quote['shipping_amount'] === 0.0 ? 'GRATIS' : $quote['display_amount'];
            $quote['source'] = 'COUPON';
            $quote['message'] = 'Tu cupón aplica para envío gratis.';
            $quote['coupon_discount'] = number_format($shippingDiscount, 2, '.', '');
        }

        return response()->json([
            'resultado' => true,
            'mensaje' => (string) $quote['message'],
            'cotizacion' => $quote,
            'valorenvio' => $quote['shipping_amount'],
            'montominimoenvio' => $quote['minimum_free_shipping'],
            'ENVIO_GRATIS' => (float) $quote['shipping_amount'] === 0.0,
            'ENVIO_VALOR' => $quote['shipping_amount'],
            'ENVIO_VALOR_TXT' => $quote['display_amount'],
            'TiendaDomicilio' => data_get($cart, 'cart.fulfillment.storeCode'),
        ]);
    }

    public function validateCheckout(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $result = $this->carts->validateForCheckout(
            strtolower((string) $country->pai_codigo), $visitor, $customer, true,
        );

        return response()->json([
            ...$this->legacy($result, true),
            'resultado' => true,
            'valido' => (bool) ($result['ok'] ?? false),
            'mensaje' => (string) ($result['message'] ?? 'Carrito validado.'),
            'validation' => $result['validation'] ?? [],
        ]);
    }

    public function order(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $data = $request->validate([
            'operation_uuid' => ['required', 'uuid'],
            'customer' => ['required', 'array'],
            'customer.firstName' => ['required', 'string', 'max:30'],
            'customer.lastName' => ['required', 'string', 'max:30'],
            'customer.email' => ['required', 'email', 'max:50'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.documentType' => ['required', 'string', 'max:50'],
            'customer.document' => ['required', 'string', 'max:50'],
            'customer.countryId' => ['nullable', 'integer'],
            'customer.stateId' => ['required', 'integer', 'exists:stj_world_states,id'],
            'customer.cityId' => ['required', 'integer', 'exists:stj_world_cities,id'],
            'customer.address' => ['required', 'string', 'max:200'],
            'pickup' => ['nullable', 'array'],
            'pickup.samePerson' => ['nullable', 'boolean'],
            'pickup.person' => ['nullable', 'string', 'max:100', 'not_regex:/[<>]/'],
            'pickup.phone' => ['nullable', 'string', 'max:100', 'not_regex:/[<>]/'],
            'pickup.identification' => ['nullable', 'string', 'max:50', 'not_regex:/[<>]/'],
            'cash_change' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $previous = DB::table('stj_carrito_operaciones')
            ->where('cao_uuid', $data['operation_uuid'])
            ->where('cao_tipo', 'ORDER_CREATE')
            ->where('cao_usu_id', $customer->getKey())
            ->first();
        if ($previous) {
            return response()->json($this->legacyOrderResponse(
                json_decode((string) $previous->cao_respuesta, true),
            ));
        }
        $validLocation = DB::table('stj_world_cities as city')
            ->join('stj_world_states as state', 'state.id', '=', 'city.state_id')
            ->where('city.id', (int) data_get($data, 'customer.cityId'))
            ->where('state.id', (int) data_get($data, 'customer.stateId'))
            ->where('state.country_id', (int) $country->pai_id_world)
            ->exists();
        if (! $validLocation) {
            throw ValidationException::withMessages(['customer.cityId' => 'La ubicacion de facturacion no pertenece al pais seleccionado.']);
        }
        $cart = $this->cartInCurrentStore($request, $country, $visitor, $customer);
        if (data_get($cart, 'cart.type') !== 'TIENDA') {
            throw ValidationException::withMessages(['payment_type' => 'Esta etapa mobile solo permite efectivo con retiro en tienda.']);
        }

        $result = DB::transaction(function () use ($country, $visitor, $customer, $data) {
            $this->carts->startCheckout(strtolower((string) $country->pai_codigo), $visitor, $customer, [
                'operation_uuid' => (string) Str::uuid(),
                'email' => (string) data_get($data, 'customer.email'),
                '_selected_only' => true,
            ]);

            $trustedCustomer = $data['customer'];
            $trustedCustomer['countryId'] = (int) $country->pai_id_world;

            return $this->orders->createFromCart(strtolower((string) $country->pai_codigo), $visitor, $customer, [
                'operation_uuid' => $data['operation_uuid'],
                'customer' => $trustedCustomer,
                'pickup' => $data['pickup'] ?? ['samePerson' => true],
                'payment_type' => 'EFECTIVO',
                '_origin' => 'APP',
                '_cash_change' => $data['cash_change'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json($this->legacyOrderResponse($result), 201);
    }

    private function legacyOrderResponse(array $result): array
    {
        return [
            'resultado' => true,
            'respuesta' => (string) ($result['message'] ?? 'Pedido creado.'),
            'pedido' => data_get($result, 'order.pedidoId'),
            'idPago' => data_get($result, 'order.pagoId'),
            'ppa_ref' => data_get($result, 'order.paymentRef'),
            'ppa_articulos' => data_get($result, 'order.articleCount'),
            'ppa_monto' => data_get($result, 'order.total'),
            'order' => $result['order'] ?? null,
        ];
    }

    public function coupons(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $applications = $this->coupons->revalidateForIdentity(
            strtolower((string) $country->pai_codigo), $visitor, $customer, (string) $customer->usu_correo,
        );

        return response()->json($this->couponPayload(
            $country,
            $customer,
            data_get($applications, 'applications', []),
            $applications,
        ));
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $resolution = $this->coupons->add(strtolower((string) $country->pai_codigo), $visitor, $customer, [
            'operation_uuid' => (string) Str::uuid(),
            'code' => $data['code'],
            'email' => (string) $customer->usu_correo,
        ]);
        $applications = data_get($resolution, 'applications', []);
        $application = collect($applications)->first(fn ($item) => strtoupper((string) ($item['code'] ?? '')) === strtoupper(trim($data['code'])));
        $valid = ($application['status'] ?? null) === 'APLICADO';
        $payload = $this->couponPayload($country, $customer, $applications, $resolution);

        return response()->json($payload + [
            'valido' => $valid,
            'mensaje' => $valid ? 'Cupón aplicado.' : (string) ($application['reason'] ?? 'El cupón no aplica al carrito actual.'),
        ]);
    }

    public function destroyCoupon(Request $request, int $application): JsonResponse
    {
        [$customer, $country, $visitor] = $this->context($request);
        $this->cartInCurrentStore($request, $country, $visitor, $customer);
        $resolution = $this->coupons->remove(strtolower((string) $country->pai_codigo), $application, $visitor, $customer, [
            'operation_uuid' => (string) Str::uuid(),
            'email' => (string) $customer->usu_correo,
        ]);

        return response()->json($this->couponPayload(
            $country, $customer, data_get($resolution, 'applications', []), $resolution,
        ) + ['mensaje' => 'Cupón eliminado.']);
    }

    private function cartInCurrentStore(Request $request, object $country, StorefrontVisitor $visitor, StorefrontCustomer $customer): array
    {
        $result = $this->carts->get(strtolower($country->pai_codigo), $visitor, $customer);
        $type = strtoupper((string) $request->query('tipoServicio')) === 'TIENDA' ? 'TIENDA' : 'DOMICILIO';
        $store = trim((string) $request->query('codigoTienda', ''));
        $currentType = (string) data_get($result, 'cart.type');
        $currentStore = (string) data_get($result, 'cart.fulfillment.storeCode');
        if ($currentType === $type && ($store === '' || $currentStore === $store)) {
            return $result;
        }

        return $this->carts->applyFulfillment(strtolower($country->pai_codigo), $visitor, $customer, [
            'operation_uuid' => (string) Str::uuid(),
            'fulfillment_type' => $type,
            'store_code' => $store !== '' ? $store : null,
            'confirm_affected' => true,
        ]);
    }

    private function context(Request $request): array
    {
        $customer = $request->user();
        if (! $customer instanceof StorefrontCustomer || ! $customer->tokenCan('mobile:account')) {
            abort(403, 'Sesion mobile no valida.');
        }
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'plataforma' => ['nullable', Rule::in(['IOS', 'ANDROID', 'WEB'])],
        ]);
        $country = DB::table('stj_paises')->where('pai_id', $data['countryId'])->first(['pai_id', 'pai_id_world', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }
        $tokenId = (string) ($customer->currentAccessToken()?->getKey() ?? 'customer');
        $hex = substr(hash('sha256', "stj-mobile:{$customer->getKey()}:{$tokenId}"), 0, 32);
        $uuid = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        $now = now();
        $visitor = StorefrontVisitor::query()->firstOrCreate(['vis_uuid' => $uuid], [
            'vis_origen' => 'MOBILE', 'vis_pais_id' => $country->pai_id,
            'vis_primera_visita' => $now, 'vis_ultima_visita' => $now,
            'vis_expira_en' => $now->copy()->addYear(), 'vis_creado_en' => $now, 'vis_actualizado_en' => $now,
        ]);
        $visitor->forceFill(['vis_pais_id' => $country->pai_id, 'vis_ultima_visita' => $now, 'vis_expira_en' => $now->copy()->addYear(), 'vis_actualizado_en' => $now])->save();

        return [$customer, $country, $visitor];
    }

    private function legacy(array $result, bool $selectedOnly = false): array
    {
        $items = collect(data_get($result, 'cart.items', []));
        if ($selectedOnly) {
            $items = $items->where('selected', true);
        }
        $products = DB::table('stj_productos')->whereIn('pro_id', $items->pluck('productId'))->get([
            'pro_id', 'pro_codigo', 'pro_nombre', 'pro_marca', 'pro_oc_marca', 'pro_thumbs', 'pro_categoria',
        ])->keyBy('pro_id');
        $cartType = (string) data_get($result, 'cart.type', 'DOMICILIO');
        $rows = $items->map(function (array $item) use ($products, $cartType) {
            $product = $products->get($item['productId']);
            $regular = (float) $item['regularPrice'];
            $final = (float) $item['finalPrice'];
            $discount = $regular > 0 ? round((1 - ($final / $regular)) * 100, 4) : 0;

            return [
                'car_id' => $item['id'], 'car_tipo' => $cartType,
                'car_cantidad' => $item['quantity'], 'car_talla' => $item['size'],
                'car_descuento' => max(0, $discount),
                'car_promocion' => data_get($item, 'promotion.name', data_get($item, 'promotion.title', '')),
                'car_seleccionado' => $item['selected'] ? 'SI' : 'NO',
                'pro_id' => $item['productId'], 'pro_codigo' => $item['sku'],
                'pro_nombre' => $product->pro_nombre ?? $item['name'],
                'pro_marca' => $product->pro_marca ?? $product->pro_oc_marca ?? '',
                'pro_oc_marca' => $product->pro_oc_marca ?? $product->pro_marca ?? '',
                'pro_thumbs' => $product->pro_thumbs ?? null, 'foto' => $item['imageUrl'],
                'pro_categoria' => $product->pro_categoria ?? null, 'ppa_precio' => $regular,
                'disponibilidad' => $item['unavailableReason'] ?? '',
                'disponibilidadError' => $item['status'] === 'DISPONIBLE' ? 0 : 1,
            ];
        })->values()->all();
        $allItems = collect(data_get($result, 'cart.items', []));
        $units = $allItems->sum(fn ($item) => (int) $item['quantity']);
        $selectedUnits = $allItems->where('selected', true)->sum(fn ($item) => (int) $item['quantity']);

        return [
            'resultado' => true, 'mensaje' => '', 'productos' => $rows,
            'total' => $units, 'total_tipo' => $units,
            'monto_tipo' => number_format((float) data_get($result, 'cart.totals.total', 0), 2, '.', ''),
            'monto_tipo_desc' => number_format((float) data_get($result, 'cart.totals.total', 0), 2, '.', ''),
            'seleccionados' => $selectedUnits, 'cartVersion' => data_get($result, 'cart.version'),
            'ENVIO_GRATIS' => false, 'ENVIO_VALOR' => 0, 'ENVIO_VALOR_TXT' => '',
        ];
    }

    private function couponPayload(object $country, StorefrontCustomer $customer, array $applications, array $resolution): array
    {
        $available = $this->coupons->available(strtolower((string) $country->pai_codigo), $customer);
        $byCoupon = collect($available)->keyBy('id');
        $applied = collect($applications)->map(function (array $application) use ($byCoupon) {
            $coupon = $byCoupon->get((int) ($application['couponId'] ?? 0), []);

            return $this->legacyCoupon($coupon) + [
                'id' => $application['id'],
                'pcu_id' => $application['id'],
                'cup_id' => $application['couponId'],
                'cup_codigo' => $application['code'],
                'status' => $application['status'],
                'reason' => $application['reason'],
                'productDiscount' => $application['productDiscount'],
                'shippingDiscount' => $application['shippingDiscount'],
            ];
        })->values()->all();

        return [
            'resultado' => true,
            'cupones' => $applied,
            'disponibles' => collect($available)->map(fn (array $coupon) => $this->legacyCoupon($coupon))->values()->all(),
            'totals' => $resolution['totals'] ?? ['couponDiscount' => '0.00', 'shippingDiscount' => '0.00'],
        ];
    }

    private function legacyCoupon(array $coupon): array
    {
        return [
            ...$coupon,
            'cup_id' => $coupon['id'] ?? null,
            'cup_codigo' => $coupon['code'] ?? null,
            'che_nombre' => $coupon['commercialName'] ?? $coupon['name'] ?? null,
            'che_tipo' => $coupon['type'] ?? null,
            'cup_descuento' => $coupon['discount'] ?? 0,
            'che_descuento' => $coupon['discount'] ?? 0,
            'cup_monto' => $coupon['amount'] ?? 0,
            'che_monto' => $coupon['amount'] ?? 0,
            'che_checkout' => $coupon['checkout'] ?? null,
            'che_solo_primera_compra' => ($coupon['firstPurchaseOnly'] ?? false) ? 'SI' : 'NO',
            'che_final' => $coupon['endsAt'] ?? null,
            'che_aplica_promo' => $coupon['promotionRule'] ?? null,
        ];
    }
}
