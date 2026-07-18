<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StorefrontAccountController extends BaseController
{
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

        $expiresAt = Carbon::now()->addHours(3);
        $customer->tokens()->where('name', 'storefront-customer')->delete();
        $token = $customer->createToken('storefront-customer', ['storefront:account'], $expiresAt);

        return $this->success([
            'customer' => $this->profile($customer),
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toISOString(),
        ], 'Sesion iniciada');
    }

    public function show(Request $request)
    {
        $customer = $request->user();

        if (! $customer instanceof StorefrontCustomer) {
            return $this->error('Esta sesion no pertenece a un cliente del storefront.', 403);
        }

        $orders = DB::table('stj_pedidos')
            ->where(function ($query) use ($customer) {
                $query->where('ped_user', $customer->getKey())
                    ->orWhere('ped_email', $customer->usu_usuario);
            })
            ->orderByDesc('ped_fecha')
            ->limit(10)
            ->get(['ped_id', 'ped_fecha', 'ped_estatus'])
            ->map(fn ($order) => [
                'id' => $order->ped_id,
                'date' => $order->ped_fecha,
                'status' => $order->ped_estatus,
            ]);

        return $this->success([
            'customer' => $this->profile($customer),
            'orders' => $orders,
            'addresses' => [],
            'payment_methods' => [],
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

        return $this->success(DB::table('stj_world_countries')->orderBy('name')->get(['id', 'name', 'phonecode']));
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
