<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use App\Support\StorefrontImageUrl;
use App\Services\StorefrontFavoriteService;
use App\Services\StorefrontWelcomeCouponService;
use App\Services\StorefrontPasswordResetService;
use App\Services\CustomerAccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\CouponProductScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\RateLimiter;

class StorefrontAccountController extends BaseController
{
    public function __construct(
        private readonly StorefrontWelcomeCouponService $welcomeCoupons,
        private readonly StorefrontPasswordResetService $passwordResets,
        private readonly CustomerAccountDeletionService $accountDeletion,
    ) {}

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $customer = StorefrontCustomer::query()
            ->where('usu_usuario', strtolower(trim($credentials['email'])))
            ->where('usu_activo', 1)
            ->first();

        if (! $customer || ! Hash::check($credentials['password'], $customer->getAuthPassword())) {
            return $this->error('El correo o la contrasena no son correctos.', 401);
        }

        $visitor = $request->attributes->get('storefrontVisitor');
        $favoriteService = app(StorefrontFavoriteService::class);
        if ($visitor) $favoriteService->merge($visitor, $customer);

        return $this->success($this->sessionPayload($customer) + ['favorites' => $favoriteService->consolidated($customer)], 'Sesion iniciada');
    }

    public function forgotPassword(Request $request, string $country)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);
        $country = strtoupper($country);

        if (! DB::table('stj_paises')->where('pai_codigo', $country)->exists()) {
            return $this->error('El pais del storefront no es valido.', 422);
        }

        $email = strtolower(trim($data['email']));
        $key = 'storefront-password-reset:'.hash('sha256', ($request->ip() ?? '').'|'.$email);

        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 900);
            $this->passwordResets->request($email, $country, $request->ip());
        }

        return $this->success([], 'Si existe una cuenta con ese correo, enviaremos un enlace para restablecer la contrasena.');
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        if (! $this->passwordResets->reset(strtolower($data['token']), $data['password'])) {
            return $this->error('El enlace no es valido, ya fue utilizado o ha vencido.', 422);
        }

        return $this->success([], 'Tu contrasena fue actualizada. Ya puedes iniciar sesion.');
    }

    public function requestPasswordChange(Request $request)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer) return $this->error('No autorizado.', 403);

        $email = strtolower(trim((string) ($customer->usu_correo ?: $customer->usu_usuario)));
        $key = 'storefront-authenticated-password-change:'.$customer->getKey();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return $this->error('Has solicitado varios enlaces. Espera unos minutos antes de intentarlo nuevamente.', 429);
        }

        RateLimiter::hit($key, 900);
        if (! $this->passwordResets->request($email, '', $request->ip())) {
            return $this->error('No pudimos enviar el correo en este momento. Tu sesión continúa activa.', 503);
        }

        $request->user()?->currentAccessToken()?->delete();

        return $this->success([], 'Te enviamos un enlace seguro para cambiar tu contraseña. La sesión fue cerrada.');
    }

    public function destroy(Request $request)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer) return $this->error('No autorizado.', 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'in:ELIMINAR'],
        ]);

        if (! Hash::check($data['password'], $customer->getAuthPassword())) {
            return $this->error('La contraseña no es correcta.', 422);
        }

        $this->accountDeletion->delete($customer);

        $request->attributes->set('forgetStorefrontVisitor', true);

        return $this->success([], 'Tu cuenta fue eliminada y todas las sesiones fueron cerradas.');
    }

    public function registrationCountries()
    {
        return $this->success(DB::table('stj_world_countries')
            ->orderByRaw("FIELD(iso2, 'SV', 'GT', 'CR', 'HN', 'PA') = 0")
            ->orderByRaw("FIELD(iso2, 'SV', 'GT', 'CR', 'HN', 'PA')")
            ->orderBy('name')
            ->get(['id', 'iso2', 'name', 'phonecode']));
    }

    public function register(Request $request, string $country)
    {
        $storefrontCountry = DB::table('stj_paises')
            ->where('pai_codigo', strtoupper($country))
            ->first(['pai_id', 'pai_codigo']);

        if (! $storefrontCountry) return $this->error('El pais del storefront no es valido.', 422);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today', 'after_or_equal:'.now()->subYears(120)->toDateString()],
            'phone_country_id' => ['required', 'integer', 'exists:stj_world_countries,id'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $email = strtolower(trim($data['email']));
        if (StorefrontCustomer::query()->whereRaw('LOWER(usu_usuario) = ?', [$email])->exists()) {
            return response()->json(['ok' => false, 'message' => 'Este correo ya esta registrado.', 'errors' => ['email' => ['Este correo ya esta registrado.']]], 422);
        }

        $phoneCountry = DB::table('stj_world_countries')->where('id', $data['phone_country_id'])->first(['phonecode']);
        [$customerId, $welcomeCoupon] = DB::transaction(function () use ($data, $email, $phoneCountry, $storefrontCountry) {
            $customerId = DB::table('stj_usuarios')->insertGetId([
                'usu_usuario' => $email,
                'usu_password' => Hash::make($data['password']),
                'usu_nombre' => trim($data['first_name']),
                'usu_apellido' => trim($data['last_name']),
                'usu_telefono_pais' => '+'.ltrim((string) $phoneCountry->phonecode, '+'),
                'usu_telefono' => trim($data['phone']),
                'usu_correo' => $email,
                'usu_fecha_nacimiento' => $data['birth_date'] ?: null,
                'usu_tipo_login' => 'WEB',
                'usu_perfil' => (string) round(microtime(true) * 1000),
                'usu_fecha_registro' => now(),
                'usu_foto_perfil' => '',
                'usu_suscrito_mailing' => 0,
                'usu_pais_registro' => $storefrontCountry->pai_id,
                'usu_activo' => 1,
            ]);

            $coupon = $this->welcomeCoupons->issue(
                (int) $storefrontCountry->pai_id,
                (string) $storefrontCountry->pai_codigo,
                $email,
                trim($data['first_name'].' '.$data['last_name']),
            );

            return [$customerId, $coupon];
        });

        $this->welcomeCoupons->sendWelcomeEmail($welcomeCoupon);

        $customer = StorefrontCustomer::query()->findOrFail($customerId);

        $favoriteService = app(StorefrontFavoriteService::class);
        $visitor = $request->attributes->get('storefrontVisitor');
        if ($visitor) $favoriteService->merge($visitor, $customer);

        return $this->success($this->sessionPayload($customer) + ['favorites' => $favoriteService->consolidated($customer)], 'Cuenta creada correctamente');
    }

    public function show(Request $request)
    {
        $customer = $request->user();

        if (! $customer instanceof StorefrontCustomer) {
            return $this->error('Esta sesion no pertenece a un cliente del storefront.', 403);
        }

        $orders = DB::table('stj_pedidos as orders')
            ->leftJoin('stj_pedidos_direccion as order_addresses', 'orders.ped_id', '=', 'order_addresses.pdi_pedido')
            ->leftJoin('stj_direcciones as addresses', 'order_addresses.pdi_direccion', '=', 'addresses.dir_id')
            ->leftJoin('stj_tiendas as stores', function ($join) {
                $join->on('orders.ped_tienda', '=', 'stores.tie_codigo')
                    ->on('stores.tie_pais', '=', 'orders.ped_id_pais');
            })
            ->leftJoin('stj_pedidos_pago as payments', 'payments.ppa_pedido', '=', 'orders.ped_id')
            ->leftJoin('stj_mensajes_fac as messages', function ($join) {
                $join->on('messages.mfa_tarjeta', '=', 'payments.ppa_emisor')
                    ->on('messages.mfa_codigo', '=', 'payments.ppa_rsp_codigo');
            })
            ->where(function ($query) use ($customer) {
                $query->where('orders.ped_user', $customer->getKey())
                    ->orWhere('orders.ped_email', $customer->usu_correo ?: $customer->usu_usuario);
            })
            ->where('payments.ppa_estado', 'APROBADA')
            ->orderByDesc('payments.ppa_fecha')
            ->limit(10)
            ->get([
                'payments.ppa_ref', 'payments.ppa_fecha', 'stores.tie_nombre',
                'orders.ped_checkout', 'payments.ppa_monto',
            ])
            ->map(fn ($order) => [
                'reference' => $order->ppa_ref,
                'date' => $order->ppa_fecha,
                'store' => $order->tie_nombre,
                'checkout' => $order->ped_checkout,
                'amount' => $order->ppa_monto !== null ? (float) $order->ppa_monto : null,
            ]);

        return $this->success([
            'customer' => $this->profile($customer),
            'orders' => $orders,
            'addresses' => $this->addresses($customer),
            'coupons' => $this->coupons($customer),
        ]);
    }

    public function order(Request $request, string $reference)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer) return $this->error('No autorizado.', 403);

        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 100) {
            return $this->error('La referencia del pedido no es valida.', 422);
        }

        $order = DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', function ($join) use ($reference) {
                $join->on('payments.ppa_pedido', '=', 'orders.ped_id')
                    ->where('payments.ppa_ref', '=', $reference)
                    ->where('payments.ppa_estado', '=', 'APROBADA');
            })
            ->leftJoin('stj_tiendas as stores', function ($join) {
                $join->on('orders.ped_tienda', '=', 'stores.tie_codigo')
                    ->on('stores.tie_pais', '=', 'orders.ped_id_pais');
            })
            ->where(function ($query) use ($customer) {
                $query->where('orders.ped_user', $customer->getKey())
                    ->orWhere('orders.ped_email', $customer->usu_correo ?: $customer->usu_usuario);
            })
            ->orderByDesc('payments.ppa_id')
            ->first([
                'orders.ped_id', 'orders.ped_id_pais', 'orders.ped_fecha', 'orders.ped_estatus',
                'orders.ped_checkout', 'orders.ped_tienda', 'payments.ppa_ref', 'payments.ppa_fecha',
                'payments.ppa_tipo', 'payments.ppa_estado', 'payments.ppa_monto', 'stores.tie_nombre',
            ]);

        if (! $order) return $this->error('Pedido no encontrado.', 404);

        $items = DB::table('stj_pedidos_detalle as detail')
            ->leftJoin('stj_productos as products', 'products.pro_id', '=', 'detail.car_producto')
            ->where('detail.car_ref', $reference)
            ->where('detail.car_pais', $order->ped_id_pais)
            ->where('detail.car_accion', 'AGREGADO')
            ->orderBy('detail.car_id')
            ->get([
                'detail.car_id', 'detail.car_producto', 'detail.car_estilo_final', 'detail.car_talla_final',
                'detail.car_talla', 'detail.car_cantidad', 'detail.car_precio', 'detail.car_descuento_final',
                'detail.car_descuento', 'products.pro_codigo', 'products.pro_nombre', 'products.pro_thumbs',
            ])
            ->map(function ($item) {
                $price = (float) ($item->car_precio ?? 0);
                $discount = (float) ($item->car_descuento_final ?? $item->car_descuento ?? 0);
                $quantity = max(1, (int) ($item->car_cantidad ?? 1));

                return [
                    'id' => (int) $item->car_id,
                    'name' => $item->pro_nombre ?: 'Producto',
                    'code' => $item->car_estilo_final ?: $item->pro_codigo,
                    'size' => $item->car_talla_final ?: $item->car_talla,
                    'quantity' => $quantity,
                    'price' => $price,
                    'discount' => $discount,
                    'unitPrice' => round($price * (1 - min(100, max(0, $discount)) / 100), 2),
                    'imageUrl' => StorefrontImageUrl::image($item->pro_thumbs, 'p100'),
                ];
            })
            ->values();

        return $this->success([
            'reference' => $order->ppa_ref,
            'date' => $order->ppa_fecha ?: $order->ped_fecha,
            'status' => $order->ped_estatus,
            'paymentStatus' => $order->ppa_estado,
            'paymentType' => $order->ppa_tipo,
            'checkout' => $order->ped_checkout,
            'store' => $order->tie_nombre,
            'storeCode' => $order->ped_tienda,
            'amount' => $order->ppa_monto !== null ? (float) $order->ppa_monto : null,
            'items' => $items,
        ]);
    }

    public function logout(Request $request)
    {
        if (! $request->user() instanceof StorefrontCustomer) {
            return $this->error('Esta sesion no pertenece a un cliente del storefront.', 403);
        }

        $request->user()?->currentAccessToken()?->delete();

        return $this->success([], 'Sesion cerrada');
    }

    public function update(Request $request)
    {
        $customer = $request->user();

        if (! $customer instanceof StorefrontCustomer) {
            return $this->error('Esta sesion no pertenece a un cliente del storefront.', 403);
        }

        $data = $request->validate([
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after_or_equal:'.now()->subYears(120)->toDateString()],
            'document_type' => ['required', Rule::in(['DUI', 'DPI', 'Cédula', 'Carné de residente', 'Licencia de conducir', 'Pasaporte', 'Otro'])],
            'document' => ['required', 'string', 'max:50'],
            'country_id' => ['required', 'integer', 'exists:stj_world_countries,id'],
            'state_id' => ['required', 'integer', 'exists:stj_world_states,id'],
            'city_id' => ['required', 'integer', 'exists:stj_world_cities,id'],
            'billing_address' => ['required', 'string', 'max:500'],
            'phone' => ['required', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
        ]);

        $country = DB::table('stj_world_countries')->where('id', $data['country_id'])->first(['name', 'phonecode']);
        $state = DB::table('stj_world_states')->where('id', $data['state_id'])->where('country_id', $data['country_id'])->first(['id', 'name']);
        $city = $state ? DB::table('stj_world_cities')->where('id', $data['city_id'])->where('state_id', $data['state_id'])->first(['id', 'name']) : null;

        if (! $country || ! $state || ! $city) {
            return $this->error('La ubicacion seleccionada no es valida.', 422);
        }

        $phoneCode = '+'.ltrim((string) $country->phonecode, '+');
        $customer->forceFill([
            'usu_fecha_nacimiento' => $data['birth_date'] ?: null,
            'usu_tipo_identificacion' => $data['document_type'],
            'usu_identificacion' => trim($data['document']),
            'usu_pais' => $country->name,
            'usu_estado' => $state->name,
            'usu_ciudad' => $city->name,
            'usu_direccion' => trim($data['billing_address']),
            'usu_departamento_id' => $state->id,
            'usu_municipio_id' => $city->id,
            'usu_departamento_txt' => $state->name,
            'usu_municipio_txt' => $city->name,
            'usu_telefono_pais' => $phoneCode,
            'usu_telefono' => trim($data['phone']),
            'usu_telefono_w_pais' => $data['whatsapp'] ? $phoneCode : '',
            'usu_telefono_w' => trim((string) ($data['whatsapp'] ?? '')),
        ])->save();

        return $this->success($this->profile($customer->refresh()), 'Datos actualizados');
    }

    public function countries(Request $request)
    {
        if (! $request->user() instanceof StorefrontCustomer) return $this->error('No autorizado.', 403);

        return $this->success(DB::table('stj_world_countries')
            ->orderByRaw("FIELD(iso2, 'DO', 'VE', 'HN', 'PA', 'US', 'CR', 'GT', 'SV') DESC")
            ->orderBy('name')
            ->get(['id', 'name', 'phonecode']));
    }

    public function states(Request $request, int $country)
    {
        if (! $request->user() instanceof StorefrontCustomer) return $this->error('No autorizado.', 403);

        return $this->success(DB::table('stj_world_states')->where('country_id', $country)->where('estado', 1)->orderBy('name')->get(['id', 'name']));
    }

    public function cities(Request $request, int $state)
    {
        if (! $request->user() instanceof StorefrontCustomer) return $this->error('No autorizado.', 403);

        return $this->success(DB::table('stj_world_cities')->where('state_id', $state)->orderBy('name')->get(['id', 'name']));
    }

    public function storeAddress(Request $request)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer) return $this->error('No autorizado.', 403);

        $data = $this->validatedAddress($request);
        $location = $this->addressLocation($data);
        if (! $location) return $this->error('La ubicacion seleccionada no es valida.', 422);

        DB::transaction(function () use ($customer, $data, $location) {
            $hasAddresses = DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')->exists();
            $isPrimary = ! $hasAddresses || $data['primary'];
            if ($isPrimary) $this->clearPrimaryAddress($customer);

            DB::table('stj_direcciones')->insert($this->addressValues($customer, $data, $location, $isPrimary) + [
                'dir_fecha' => now(),
            ]);
        });

        return $this->success($this->addresses($customer), 'Direccion agregada');
    }

    public function updateAddress(Request $request, int $address)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer || ! $this->ownsAddress($customer, $address)) return $this->error('Direccion no encontrada.', 404);

        $data = $this->validatedAddress($request);
        $location = $this->addressLocation($data);
        if (! $location) return $this->error('La ubicacion seleccionada no es valida.', 422);

        DB::transaction(function () use ($customer, $address, $data, $location) {
            $wasPrimary = DB::table('stj_direcciones')->where('dir_id', $address)->value('dir_principal') === 'SI';
            $isPrimary = $wasPrimary || $data['primary'];
            if ($isPrimary) $this->clearPrimaryAddress($customer);
            DB::table('stj_direcciones')->where('dir_id', $address)->where('dir_usuario', $customer->getKey())
                ->update($this->addressValues($customer, $data, $location, $isPrimary));
        });

        return $this->success($this->addresses($customer), 'Direccion actualizada');
    }

    public function makeAddressPrimary(Request $request, int $address)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer || ! $this->ownsAddress($customer, $address)) return $this->error('Direccion no encontrada.', 404);

        DB::transaction(function () use ($customer, $address) {
            $this->clearPrimaryAddress($customer);
            DB::table('stj_direcciones')->where('dir_id', $address)->where('dir_usuario', $customer->getKey())->update(['dir_principal' => 'SI']);
        });

        return $this->success($this->addresses($customer), 'Direccion principal actualizada');
    }

    public function destroyAddress(Request $request, int $address)
    {
        $customer = $this->storefrontCustomer($request);
        if (! $customer || ! $this->ownsAddress($customer, $address)) return $this->error('Direccion no encontrada.', 404);

        DB::transaction(function () use ($customer, $address) {
            $wasPrimary = DB::table('stj_direcciones')->where('dir_id', $address)->value('dir_principal') === 'SI';
            DB::table('stj_direcciones')->where('dir_id', $address)->where('dir_usuario', $customer->getKey())->update(['dir_save' => 'NO', 'dir_principal' => 'NO']);
            if ($wasPrimary) {
                $next = DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')->orderByDesc('dir_id')->value('dir_id');
                if ($next) DB::table('stj_direcciones')->where('dir_id', $next)->update(['dir_principal' => 'SI']);
            }
        });

        return $this->success($this->addresses($customer), 'Direccion eliminada');
    }

    private function validatedAddress(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['CASA', 'TRABAJO', 'OTRO'])],
            'other_type' => ['nullable', 'required_if:type,OTRO', 'string', 'max:80'],
            'same_person' => ['required', 'boolean'],
            'recipient' => ['nullable', 'required_if:same_person,false', 'string', 'max:150'],
            'recipient_phone' => ['nullable', 'required_if:same_person,false', 'string', 'max:30'],
            'country_id' => ['required', 'integer', 'exists:stj_world_countries,id'],
            'state_id' => ['required', 'integer', 'exists:stj_world_states,id'],
            'city_id' => ['required', 'integer', 'exists:stj_world_cities,id'],
            'address' => ['required', 'string', 'max:500'],
            'reference' => ['required', 'string', 'max:500'],
            'primary' => ['required', 'boolean'],
        ]);
    }

    private function addressLocation(array $data): ?array
    {
        $country = DB::table('stj_world_countries')->where('id', $data['country_id'])->first(['id', 'name']);
        $state = DB::table('stj_world_states')->where('id', $data['state_id'])->where('country_id', $data['country_id'])->first(['id', 'name']);
        $city = $state ? DB::table('stj_world_cities')->where('id', $data['city_id'])->where('state_id', $data['state_id'])->first(['id', 'name']) : null;
        return $country && $state && $city ? compact('country', 'state', 'city') : null;
    }

    private function addressValues(StorefrontCustomer $customer, array $data, array $location, bool $primary): array
    {
        return [
            'dir_usuario' => $customer->getKey(), 'dir_tipo' => $data['type'],
            'dir_tipo_otro' => $data['type'] === 'OTRO' ? trim((string) $data['other_type']) : null,
            'dir_misma_persona' => $data['same_person'] ? 'SI' : 'NO',
            'dir_misma_direccion' => 'NO',
            'dir_persona' => $data['same_person'] ? null : trim((string) $data['recipient']),
            'dir_telefono' => $data['same_person'] ? null : trim((string) $data['recipient_phone']),
            'dir_pais' => $location['country']->name, 'dir_departamento' => $location['state']->id,
            'dir_departamento_txt' => $location['state']->name, 'dir_municipio' => $location['city']->id,
            'dir_municipio_txt' => $location['city']->name, 'dir_direccion' => trim($data['address']),
            'dir_referencia' => trim($data['reference']), 'dir_save' => 'SI',
            'dir_principal' => $primary ? 'SI' : 'NO',
        ];
    }

    private function addresses(StorefrontCustomer $customer): array
    {
        return DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')
            ->orderByRaw("dir_principal = 'SI' DESC")->orderByDesc('dir_id')->get()->map(fn ($item) => [
                'id' => (int) $item->dir_id, 'type' => $item->dir_tipo, 'other_type' => $item->dir_tipo_otro,
                'same_person' => $item->dir_misma_persona === 'SI', 'recipient' => $item->dir_persona,
                'recipient_phone' => $item->dir_telefono, 'country' => $item->dir_pais,
                'state_id' => $item->dir_departamento ? (int) $item->dir_departamento : null,
                'state' => $item->dir_departamento_txt, 'city_id' => $item->dir_municipio ? (int) $item->dir_municipio : null,
                'city' => $item->dir_municipio_txt, 'address' => $item->dir_direccion,
                'reference' => $item->dir_referencia, 'primary' => $item->dir_principal === 'SI',
                'country_id' => DB::table('stj_world_countries')->where('name', $item->dir_pais)->value('id'),
            ])->all();
    }

    private function storefrontCustomer(Request $request): ?StorefrontCustomer
    {
        return $request->user() instanceof StorefrontCustomer ? $request->user() : null;
    }

    private function ownsAddress(StorefrontCustomer $customer, int $address): bool
    {
        return DB::table('stj_direcciones')->where('dir_id', $address)->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')->exists();
    }

    private function clearPrimaryAddress(StorefrontCustomer $customer): void
    {
        DB::table('stj_direcciones')->where('dir_usuario', $customer->getKey())->where('dir_save', 'SI')->update(['dir_principal' => 'NO']);
    }

    private function coupons(StorefrontCustomer $customer): array
    {
        $country = DB::table('stj_paises')
            ->where('pai_id', $customer->usu_pais_registro)
            ->first(['pai_id', 'pai_codigo', 'pai_nombre']);

        if (! $country) return [];

        $columns = [
            'c.cup_id', 'c.cup_codigo', 'c.cup_estado', 'c.cup_monto', 'c.cup_disponible', 'c.cup_fecha',
            'h.che_id', 'h.che_tipo', 'h.che_descuento', 'h.che_checkout', 'h.che_monto', 'h.che_estado as header_estado',
            'h.che_nombre', 'h.che_nombre_comercial', 'h.che_solo_primera_compra',
            'h.che_aplica_promo', 'h.che_coleccion', 'h.che_inicio', 'h.che_final', 'h.che_tipo_productos',
            'categories.cat_nombre as genero_nombre', 'collections.col_nombre as coleccion_nombre',
        ];
        $email = strtolower(trim((string) ($customer->usu_correo ?: $customer->usu_usuario)));
        $now = now();

        $personal = DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->leftJoin('stj_categorias as categories', 'categories.cat_id', '=', 'h.che_genero')
            ->leftJoin('stj_coleccion as collections', 'collections.col_id', '=', 'h.che_coleccion')
            ->whereRaw('LOWER(c.cup_correo) = ?', [$email])
            ->where('h.che_pais', $country->pai_id)
            ->select($columns)
            ->get()
            ->map(fn ($coupon) => $this->couponPayload($coupon, 'personal', $country, $now));

        $generic = DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'c.cup_header', '=', 'h.che_id')
            ->leftJoin('stj_categorias as categories', 'categories.cat_id', '=', 'h.che_genero')
            ->leftJoin('stj_coleccion as collections', 'collections.col_id', '=', 'h.che_coleccion')
            ->where('h.che_generico', 'SI')
            ->where('h.che_inicio', '<=', $now)
            ->where('h.che_final', '>=', $now)
            ->where('c.cup_estado', 'ACTIVO')
            ->where('h.che_pais', $country->pai_id)
            ->select($columns)
            ->get()
            ->map(fn ($coupon) => $this->couponPayload($coupon, 'generic', $country, $now));

        return $personal->concat($generic)
            ->unique(fn ($coupon) => $coupon['id'].'|'.$coupon['code'])
            ->sortByDesc(fn ($coupon) => sprintf('%s|%012d', $coupon['created_at'] ?? '', $coupon['id']))
            ->values()
            ->all();
    }

    private function couponPayload(object $coupon, string $source, object $country, Carbon $now): array
    {
        $startsAt = $coupon->che_inicio ? Carbon::parse($coupon->che_inicio) : null;
        $endsAt = $coupon->che_final ? Carbon::parse($coupon->che_final) : null;
        $available = $coupon->header_estado === 'ACTIVO'
            && $coupon->cup_estado === 'ACTIVO'
            && (! $startsAt || $startsAt->lte($now))
            && (! $endsAt || $endsAt->gte($now));
        $effectiveStatus = $coupon->header_estado !== 'ACTIVO' ? 'INACTIVO' : $coupon->cup_estado;
        $scope = CouponProductScope::details(
            $coupon,
            (string) $country->pai_codigo,
            (string) config('services.fcm.web_home_url', 'https://stjacks.com'),
        );

        return [
            'id' => (int) $coupon->cup_id, 'header_id' => (int) $coupon->che_id,
            'code' => $coupon->cup_codigo, 'source' => $source, 'available' => $available,
            'status' => $effectiveStatus, 'type' => $coupon->che_tipo,
            'discount' => (float) $coupon->che_descuento, 'amount' => (float) $coupon->cup_monto,
            'available_amount' => (float) $coupon->cup_disponible, 'minimum_amount' => (float) $coupon->che_monto,
            'name' => $coupon->che_nombre, 'commercial_name' => $coupon->che_nombre_comercial,
            'checkout' => $coupon->che_checkout, 'promotion_rule' => $coupon->che_aplica_promo,
            'first_purchase_only' => $coupon->che_solo_primera_compra === 'SI',
            'gender' => $coupon->genero_nombre, 'collection' => $coupon->che_coleccion,
            'starts_at' => $coupon->che_inicio, 'ends_at' => $coupon->che_final,
            'created_at' => $coupon->cup_fecha, 'product_scope' => $coupon->che_tipo_productos,
            'products_scope_label' => $scope['label'],
            'products_link' => $scope['url'], 'products_link_label' => $scope['url'] ? 'Ver productos que aplican' : null,
            'country' => ['id' => (int) $country->pai_id, 'code' => strtolower($country->pai_codigo), 'name' => $country->pai_nombre],
        ];
    }

    private function sessionPayload(StorefrontCustomer $customer): array
    {
        $expiresAt = Carbon::now()->addHours(3);
        $customer->tokens()->where('name', 'storefront-customer')->delete();
        $token = $customer->createToken('storefront-customer', ['storefront:account'], $expiresAt);

        return [
            'customer' => $this->profile($customer),
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    private function profile(StorefrontCustomer $customer): array
    {
        $countryId = $customer->usu_pais
            ? DB::table('stj_world_countries')->where('name', $customer->usu_pais)->value('id')
            : null;

        return [
            'id' => $customer->getKey(),
            'first_name' => $customer->usu_nombre,
            'last_name' => $customer->usu_apellido,
            'name' => trim("{$customer->usu_nombre} {$customer->usu_apellido}"),
            'email' => $customer->usu_correo ?: $customer->usu_usuario,
            'phone' => trim("{$customer->usu_telefono_pais} {$customer->usu_telefono}"),
            'phone_code' => $customer->usu_telefono_pais,
            'phone_number' => $customer->usu_telefono,
            'whatsapp_code' => $customer->usu_telefono_w_pais,
            'whatsapp' => $customer->usu_telefono_w,
            'document_type' => $customer->usu_tipo_identificacion,
            'document' => $customer->usu_identificacion,
            'birth_date' => $customer->usu_fecha_nacimiento,
            'country' => $customer->usu_pais,
            'country_id' => $countryId ? (int) $countryId : null,
            'state' => $customer->usu_estado,
            'city' => $customer->usu_ciudad,
            'state_id' => $customer->usu_departamento_id,
            'city_id' => $customer->usu_municipio_id,
            'billing_address' => $customer->usu_direccion,
            'photo' => $customer->usu_foto_perfil,
            'registered_at' => $customer->usu_fecha_registro,
        ];
    }
}
