<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Services\Mobile\MobilePushSubscriptionService;
use App\Services\StorefrontPasswordResetService;
use App\Services\StorefrontWelcomeCouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MobileAuthController extends Controller
{
    public function __construct(
        private readonly MobilePushSubscriptionService $pushSubscriptions,
        private readonly StorefrontWelcomeCouponService $welcomeCoupons,
        private readonly StorefrontPasswordResetService $passwordResets,
    ) {}

    public function recoverPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        $allowedCodes = collect(config('mobile.registration_country_codes', ['SV', 'GT', 'CR', 'HN']))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->all();
        $country = DB::table('stj_paises')
            ->where('pai_id', (int) $data['countryId'])
            ->whereIn(DB::raw('UPPER(pai_codigo)'), $allowedCodes)
            ->first(['pai_codigo']);
        if (! $country) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Pais no soportado.'], 422);
        }

        $email = strtolower(trim((string) $data['email']));
        $key = 'mobile-password-reset:'.hash('sha256', ($request->ip() ?? '').'|'.$email);
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 900);
            $this->passwordResets->request($email, strtoupper((string) $country->pai_codigo), $request->ip());
        }

        return response()->json([
            'resultado' => 'true',
            'mensaje' => 'Si existe una cuenta con ese correo, enviaremos un enlace para restablecer la contrasena.',
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'nombres' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\pL\\s'.-]+$/u"],
            'apellidos' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\pL\\s'.-]+$/u"],
            'email' => ['required', 'email', 'max:150'],
            'fechaNac' => ['nullable', 'date', 'before:today', 'after_or_equal:'.now()->subYears(120)->toDateString()],
            'telefono' => ['required', 'string', 'min:8', 'max:30', 'regex:/^[+ 0-9-]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'token' => ['nullable', 'string', 'max:512'],
            'idSesion' => ['nullable', 'string', 'max:150'],
            'installationId' => ['nullable', 'uuid'],
            'environment' => ['nullable', Rule::in(['TEST', 'PRODUCTION'])],
            'dispositivo' => ['required', 'string', Rule::in(['IOS', 'ANDROID', 'WEB'])],
        ]);

        $allowedCodes = collect(config('mobile.registration_country_codes', ['SV', 'GT', 'CR', 'HN']))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->all();
        $country = DB::table('stj_paises')
            ->where('pai_id', (int) $data['countryId'])
            ->whereIn(DB::raw('UPPER(pai_codigo)'), $allowedCodes)
            ->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Pais no soportado.'], 422);
        }

        $email = strtolower(trim((string) $data['email']));
        if (StorefrontCustomer::query()
            ->where(fn ($query) => $query->whereRaw('LOWER(usu_usuario) = ?', [$email])->orWhereRaw('LOWER(usu_correo) = ?', [$email]))
            ->exists()) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'El usuario '.$email.' ya se encuentra registrado.']);
        }

        $countryCode = strtoupper((string) $country->pai_codigo);
        $countryNames = ['SV' => 'El Salvador', 'GT' => 'Guatemala', 'CR' => 'Costa Rica', 'HN' => 'Honduras'];
        $phoneCodes = ['SV' => '+503', 'GT' => '+502', 'CR' => '+506', 'HN' => '+504'];
        $customerId = DB::table('stj_usuarios')->insertGetId([
            'usu_usuario' => $email,
            'usu_password' => Hash::make((string) $data['password']),
            'usu_nombre' => trim((string) $data['nombres']),
            'usu_apellido' => trim((string) $data['apellidos']),
            'usu_telefono_pais' => $phoneCodes[$countryCode] ?? '',
            'usu_telefono' => trim((string) $data['telefono']),
            'usu_correo' => $email,
            'usu_fecha_nacimiento' => filled($data['fechaNac'] ?? null) ? $data['fechaNac'] : null,
            'usu_tipo_login' => 'APP',
            'usu_perfil' => (string) round(microtime(true) * 1000),
            'usu_fecha_registro' => now(),
            'usu_foto_perfil' => '',
            'usu_suscrito_mailing' => 0,
            'usu_pais_registro' => (int) $country->pai_id,
            'usu_pais' => $countryNames[$countryCode] ?? $countryCode,
            'usu_activo' => 1,
        ]);
        $customer = StorefrontCustomer::query()->findOrFail($customerId);

        try {
            $coupon = $this->welcomeCoupons->issue(
                (int) $country->pai_id,
                $countryCode,
                $email,
                trim((string) $data['nombres'].' '.(string) $data['apellidos']),
            );
            $this->welcomeCoupons->sendWelcomeEmail($coupon);
        } catch (Throwable $exception) {
            Log::warning('No se pudo generar el cupon de bienvenida durante el registro mobile.', [
                'userId' => $customerId,
                'countryId' => (int) $country->pai_id,
                'exception' => $exception,
            ]);
        }

        $platform = strtoupper((string) $data['dispositivo']);
        $deviceReference = trim((string) ($data['idSesion'] ?? '')) ?: trim((string) ($data['token'] ?? ''));
        $tokenName = 'mobile-'.strtolower($platform).'-'.substr(hash('sha256', $deviceReference ?: $platform), 0, 16);
        $expiresAt = Carbon::now()->addDays((int) config('mobile.auth_token_days', 30));
        $accessToken = $customer->createToken($tokenName, ['mobile:account'], $expiresAt)->plainTextToken;

        try {
            $this->pushSubscriptions->attachCustomer(
                (string) ($data['installationId'] ?? ''),
                (string) ($data['environment'] ?? 'PRODUCTION'),
                $customer,
            );
        } catch (Throwable $exception) {
            Log::warning('No se pudo asociar la suscripcion push durante el registro mobile.', [
                'userId' => $customerId,
                'exception' => $exception,
            ]);
        }

        return response()->json($this->legacyProfile($customer) + [
            'resultado' => 'true',
            'accessToken' => $accessToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toISOString(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryId' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:512'],
            'idSesion' => ['nullable', 'string', 'max:150'],
            'installationId' => ['nullable', 'uuid'],
            'environment' => ['nullable', Rule::in(['TEST', 'PRODUCTION'])],
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

        try {
            $this->pushSubscriptions->attachCustomer(
                (string) ($data['installationId'] ?? ''),
                (string) ($data['environment'] ?? 'PRODUCTION'),
                $customer,
            );
        } catch (Throwable $exception) {
            Log::warning('No se pudo asociar la suscripcion push durante el login mobile.', [
                'userId' => $customer->getKey(),
                'installationId' => (string) ($data['installationId'] ?? ''),
                'environment' => (string) ($data['environment'] ?? 'PRODUCTION'),
                'exception' => $exception,
            ]);
        }

        return response()->json($this->legacyProfile($customer) + [
            'resultado' => 'true',
            'accessToken' => $accessToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toISOString(),
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        $customer = $this->mobileCustomer($request);
        if (! $customer) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Sesion mobile no valida.'], 403);
        }

        return response()->json($this->legacyProfile($customer) + [
            'resultado' => 'true',
            'expiresAt' => $customer->currentAccessToken()?->expires_at?->toISOString(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $customer = $this->mobileCustomer($request);
        if (! $customer) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Sesion mobile no valida.'], 403);
        }

        $data = $request->validate([
            'installationId' => ['nullable', 'uuid'],
            'environment' => ['nullable', Rule::in(['TEST', 'PRODUCTION'])],
        ]);
        try {
            $this->pushSubscriptions->detachCustomer(
                (string) ($data['installationId'] ?? ''),
                (string) ($data['environment'] ?? 'PRODUCTION'),
                $customer,
            );
        } catch (Throwable $exception) {
            Log::warning('No se pudo desvincular la suscripcion push durante el logout mobile.', [
                'userId' => $customer->getKey(),
                'installationId' => (string) ($data['installationId'] ?? ''),
                'environment' => (string) ($data['environment'] ?? 'PRODUCTION'),
                'exception' => $exception,
            ]);
        }
        $customer->currentAccessToken()?->delete();

        return response()->json(['resultado' => 'true', 'mensaje' => 'Sesion cerrada.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $customer = $this->mobileCustomer($request);
        if (! $customer) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Sesion mobile no valida.'], 403);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
        ]);

        if (! Hash::check((string) $data['current_password'], $customer->getAuthPassword())) {
            return response()->json([
                'resultado' => 'false',
                'mensaje' => 'La contrasena actual no es correcta.',
            ]);
        }
        if (Hash::check((string) $data['password'], $customer->getAuthPassword())) {
            return response()->json([
                'resultado' => 'false',
                'mensaje' => 'La nueva contrasena debe ser diferente a la actual.',
            ]);
        }

        $currentTokenId = $customer->currentAccessToken()?->getKey();
        DB::transaction(function () use ($customer, $data, $currentTokenId) {
            $customer->forceFill(['usu_password' => Hash::make((string) $data['password'])])->save();
            $customer->tokens()
                ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
                ->delete();
            DB::table('stj_storefront_password_resets')
                ->where('spr_email', strtolower(trim((string) ($customer->usu_correo ?: $customer->usu_usuario))))
                ->delete();
        });
        $this->passwordResets->sendPasswordChangedNotification($customer->refresh());

        return response()->json([
            'resultado' => 'true',
            'mensaje' => 'Tu contrasena fue actualizada correctamente.',
        ]);
    }

    public function account(Request $request): JsonResponse
    {
        $customer = $this->mobileCustomer($request);
        if (! $customer) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Sesion mobile no valida.'], 403);
        }

        return response()->json($this->legacyProfile($customer) + ['resultado' => 'true']);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        $customer = $this->mobileCustomer($request);
        if (! $customer) {
            return response()->json(['resultado' => 'false', 'mensaje' => 'Sesion mobile no valida.'], 403);
        }

        $data = $request->validate([
            'form1.idUser' => ['nullable'],
            'form1.nombres' => ['required', 'string', 'min:2', 'max:40', "regex:/^[\\pL\\s'.-]+$/u"],
            'form1.apellidos' => ['required', 'string', 'min:2', 'max:40', "regex:/^[\\pL\\s'.-]+$/u"],
            'form1.email' => ['required', 'email', 'max:150'],
            'form1.tipoIdentificacion' => ['nullable', 'string', 'max:50'],
            'form1.identificacion' => ['nullable', 'string', 'max:50'],
            'form1.pais' => ['required', 'string', 'max:100'],
            'form1.departamento' => ['required', 'integer'],
            'form1.municipio' => ['required', 'integer'],
            'form1.estado' => ['nullable', 'string', 'max:100'],
            'form1.ciudad' => ['nullable', 'string', 'max:100'],
            'form1.direccion' => ['nullable', 'string', 'max:500'],
            'form1.telefono' => ['required', 'string', 'max:30', 'regex:/^[+ 0-9-]+$/'],
            'form1.whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[+ 0-9-]*$/'],
        ])['form1'];

        $email = strtolower(trim((string) $data['email']));
        $currentEmails = collect([$customer->usu_usuario, $customer->usu_correo])
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique();
        if (! $currentEmails->contains($email)) {
            $duplicate = StorefrontCustomer::query()
                ->whereKeyNot($customer->getKey())
                ->where(function ($query) use ($email) {
                    $query->whereRaw('LOWER(usu_usuario) = ?', [$email])
                        ->orWhereRaw('LOWER(usu_correo) = ?', [$email]);
                })->exists();
            if ($duplicate) {
                return response()->json([
                    'resultado' => 'false',
                    'mensaje' => 'El correo '.$email.' ya se encuentra registrado.',
                ]);
            }
        }

        $country = DB::table('stj_world_countries')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $data['pais']), 'UTF-8')])
            ->first(['id', 'name', 'phonecode']);
        if (! $country) {
            throw ValidationException::withMessages(['form1.pais' => 'El pais seleccionado no es valido.']);
        }

        $departmentId = (int) $data['departamento'];
        $department = DB::table('stj_world_states')
            ->where('id', $departmentId)
            ->where('country_id', $country->id)
            ->where('estado', 1)
            ->first(['id', 'name']);
        if (! $department) {
            throw ValidationException::withMessages(['form1.departamento' => 'El departamento no pertenece al pais seleccionado.']);
        }

        $municipality = DB::table('stj_world_cities')
            ->where('id', (int) $data['municipio'])
            ->where('state_id', $department->id)
            ->where('country_id', $country->id)
            ->first(['id', 'name']);
        if (! $municipality) {
            throw ValidationException::withMessages(['form1.municipio' => 'El municipio no pertenece al departamento seleccionado.']);
        }

        $stateName = (string) $department->name;
        $city = (string) $municipality->name;
        $phoneCode = '+'.ltrim((string) $country->phonecode, '+');

        $customer->forceFill([
            'usu_usuario' => $email,
            'usu_correo' => $email,
            'usu_nombre' => trim((string) $data['nombres']),
            'usu_apellido' => trim((string) $data['apellidos']),
            'usu_tipo_identificacion' => trim((string) ($data['tipoIdentificacion'] ?? '')),
            'usu_identificacion' => trim((string) ($data['identificacion'] ?? '')),
            'usu_pais' => $country->name,
            'usu_departamento_id' => $department->id,
            'usu_departamento_txt' => $stateName,
            'usu_estado' => $stateName,
            'usu_municipio_id' => $municipality->id,
            'usu_municipio_txt' => $city,
            'usu_ciudad' => $city,
            'usu_direccion' => trim((string) ($data['direccion'] ?? '')),
            'usu_telefono_pais' => $phoneCode,
            'usu_telefono' => trim((string) $data['telefono']),
            'usu_telefono_w_pais' => trim((string) ($data['whatsapp'] ?? '')) !== '' ? $phoneCode : '',
            'usu_telefono_w' => trim((string) ($data['whatsapp'] ?? '')),
        ])->save();

        return response()->json($this->legacyProfile($customer->refresh()) + ['resultado' => 'true']);
    }

    private function mobileCustomer(Request $request): ?StorefrontCustomer
    {
        $user = $request->user();

        return $user instanceof StorefrontCustomer && $user->tokenCan('mobile:account') ? $user : null;
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
