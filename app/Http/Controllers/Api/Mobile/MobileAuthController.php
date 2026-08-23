<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:512'],
            'idSesion' => ['nullable', 'string', 'max:150'],
            'dispositivo' => ['required', 'string', Rule::in(['IOS', 'ANDROID', 'WEB'])],
        ]);

        if (! DB::table('stj_paises')->where('pai_id', (int) $data['countryId'])->exists()) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Pais no soportado.'], 422);
        }

        $email = strtolower(trim((string) $data['email']));
        $customer = StorefrontCustomer::query()
            ->where('usu_usuario', $email)
            ->where('usu_activo', 1)
            ->first();

        if (! $customer) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Usuario no registrado']);
        }
        if (! Hash::check((string) $data['password'], $customer->getAuthPassword())) {
            return response()->json(['resultado' => 'false', 'mensaje' => "Contrase\u{00F1}a incorrecta"]);
        }

        $platform = strtoupper((string) $data['dispositivo']);
        $deviceReference = trim((string) ($data['idSesion'] ?? '')) ?: trim((string) ($data['token'] ?? ''));
        $tokenName = 'mobile-'.strtolower($platform).'-'.substr(hash('sha256', $deviceReference ?: $platform), 0, 16);
        $expiresAt = Carbon::now()->addDays((int) config('mobile.auth_token_days', 30));

        $customer->tokens()->where('name', $tokenName)->delete();
        $accessToken = $customer->createToken($tokenName, ['mobile:account'], $expiresAt)->plainTextToken;

        $this->savePushToken($customer, $data, $platform);

        return response()->json($this->legacyProfile($customer) + [
            'resultado' => 'true',
            'accessToken' => $accessToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toISOString(),
        ]);
    }

    private function savePushToken(StorefrontCustomer $customer, array $data, string $platform): void
    {
        $pushToken = trim((string) ($data['token'] ?? ''));
        if ($pushToken === '' || ! Schema::hasTable('stj_usuarios_dispositivos')) {
            return;
        }

        DB::table('stj_usuarios_dispositivos')->updateOrInsert(
            ['dis_user' => $customer->getKey(), 'dis_token' => $pushToken],
            ['dis_fecha' => now(), 'dis_tipo_dispositivo' => $platform],
        );
    }

    private function legacyProfile(StorefrontCustomer $customer): array
    {
        return [
            'idUser' => $customer->getKey(),
            'nombre' => trim((string) $customer->usu_nombre.' '.(string) $customer->usu_apellido),
            'nombres' => $customer->usu_nombre,
            'apellidos' => $customer->usu_apellido,
            'telefono2' => trim((string) $customer->usu_telefono_pais.' '.(string) $customer->usu_telefono),
            'correo' => $customer->usu_correo,
            'tipoIdentificacion' => $customer->usu_tipo_identificacion,
            'identificacion' => $customer->usu_identificacion,
            'pais' => $customer->usu_pais,
            'departamento_id' => $customer->usu_departamento_id,
            'municipio_id' => $customer->usu_municipio_id,
            'departamento' => $customer->usu_departamento_txt,
            'municipio' => $customer->usu_municipio_txt,
            'estado' => $customer->usu_estado,
            'ciudad' => $customer->usu_ciudad,
            'direccion' => $customer->usu_direccion,
            'telefono' => $customer->usu_telefono,
            'whatsapp' => $customer->usu_telefono_w,
            'foto' => $customer->usu_foto_perfil,
        ];
    }
}
