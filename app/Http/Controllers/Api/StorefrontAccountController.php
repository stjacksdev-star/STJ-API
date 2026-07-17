<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            ->get(['ped_id', 'ped_fecha', 'ped_total', 'ped_estatus'])
            ->map(fn ($order) => [
                'id' => $order->ped_id,
                'date' => $order->ped_fecha,
                'total' => (float) $order->ped_total,
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

    private function profile(StorefrontCustomer $customer): array
    {
        return [
            'id' => $customer->getKey(),
            'first_name' => $customer->usu_nombre,
            'last_name' => $customer->usu_apellido,
            'name' => trim("{$customer->usu_nombre} {$customer->usu_apellido}"),
            'email' => $customer->usu_correo ?: $customer->usu_usuario,
            'phone' => trim("{$customer->usu_telefono_pais} {$customer->usu_telefono}"),
            'document_type' => $customer->usu_tipo_identificacion,
            'document' => $customer->usu_identificacion,
            'photo' => $customer->usu_foto_perfil,
            'registered_at' => $customer->usu_fecha_registro,
        ];
    }
}
