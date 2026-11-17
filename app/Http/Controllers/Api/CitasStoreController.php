<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CitasStoreController extends Controller
{
    private const PERMISSION = 'citas.stores.read';

    private const COUNTRY_IDS = [1, 2, 3, 5, 7];

    public function __invoke(Request $request): JsonResponse
    {
        $startedAt = hrtime(true);
        $auditUuid = (string) Str::uuid();
        $client = null;

        try {
            $token = trim((string) $request->bearerToken());
            if ($token === '') {
                $this->audit($request, $auditUuid, null, 401, 'NO_AUTORIZADO', $startedAt, 'Bearer token ausente.');

                return response()->json(['success' => false, 'message' => 'No autorizado.'], 401);
            }

            $client = DB::table('stj_api_clientes')
                ->where('apc_token_hash', hash('sha256', $token))
                ->first();

            if (! $client || $client->apc_estado !== 'ACTIVO'
                || $client->apc_permiso !== self::PERMISSION
                || ($client->apc_expira_en !== null && now()->greaterThan($client->apc_expira_en))) {
                $this->audit($request, $auditUuid, $client?->apc_id, 401, 'NO_AUTORIZADO', $startedAt, 'Token inválido, vencido o revocado.');

                return response()->json(['success' => false, 'message' => 'No autorizado.'], 401);
            }

            DB::table('stj_api_clientes')
                ->where('apc_id', $client->apc_id)
                ->update(['apc_ultimo_uso' => now()]);

            $stores = DB::table('stj_tiendas')
                ->whereIn('tie_pais', self::COUNTRY_IDS)
                ->where('tie_productos', 1)
                ->orderBy('tie_pais')
                ->orderBy('tie_nombre')
                ->get([
                    'tie_id',
                    'tie_pais',
                    'tie_nombre',
                    'tie_direccion',
                    'tie_telefono',
                    'tie_correo',
                    'tie_productos',
                ]);

            $this->audit($request, $auditUuid, $client->apc_id, 200, 'EXITOSO', $startedAt);

            return response()->json(['success' => true, 'data' => $stores]);
        } catch (Throwable $exception) {
            report($exception);
            $this->audit($request, $auditUuid, $client?->apc_id, 500, 'ERROR', $startedAt, 'Error interno.');

            return response()->json([
                'success' => false,
                'message' => 'No fue posible consultar las tiendas.',
                'request_id' => $auditUuid,
            ], 500);
        }
    }

    private function audit(
        Request $request,
        string $uuid,
        mixed $clientId,
        int $status,
        string $result,
        int $startedAt,
        ?string $message = null,
    ): void {
        try {
            DB::table('stj_api_consultas')->insert([
                'aqc_uuid' => $uuid,
                'aqc_cliente_id' => $clientId,
                'aqc_endpoint' => '/api/integraciones/citas/tiendas',
                'aqc_metodo' => 'GET',
                'aqc_referencia' => null,
                'aqc_pais_id' => null,
                'aqc_ip' => $request->ip(),
                'aqc_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'aqc_http_estado' => $status,
                'aqc_resultado' => $result,
                'aqc_duracion_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'aqc_mensaje' => $message,
                'aqc_creado_en' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('No fue posible registrar la consulta de tiendas para citas.', [
                'request_id' => $uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
