<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CorePosOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CorePosOrderController extends Controller
{
    public function __invoke(Request $request, string $stj, CorePosOrderService $orders): JsonResponse
    {
        $startedAt = hrtime(true);
        $auditUuid = (string) Str::uuid();
        $client = null;

        try {
            if (! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $stj)) {
                $this->audit($request, $auditUuid, null, $stj, 400, 'ERROR', $startedAt, 'Formato de referencia inválido.');

                return response()->json(['ok' => false, 'message' => 'La referencia STJ no es válida.'], 400);
            }

            $token = trim((string) $request->bearerToken());
            if ($token === '') {
                $this->audit($request, $auditUuid, null, $stj, 401, 'NO_AUTORIZADO', $startedAt, 'Bearer token ausente.');

                return response()->json(['ok' => false, 'message' => 'No autorizado.'], 401);
            }

            $client = DB::table('stj_api_clientes')
                ->where('apc_token_hash', hash('sha256', $token))
                ->first();

            if (! $client || $client->apc_estado !== 'ACTIVO'
                || (int) $client->apc_pais_id !== 1
                || $client->apc_permiso !== 'sv.billing.orders.read'
                || ($client->apc_expira_en !== null && now()->greaterThan($client->apc_expira_en))) {
                $this->audit($request, $auditUuid, $client?->apc_id, $stj, 401, 'NO_AUTORIZADO', $startedAt, 'Token inválido, vencido o revocado.');

                return response()->json(['ok' => false, 'message' => 'No autorizado.'], 401);
            }

            DB::table('stj_api_clientes')->where('apc_id', $client->apc_id)->update(['apc_ultimo_uso' => now()]);
            $data = $orders->findByReference($stj);

            if ($data === null) {
                $status = $orders->statusByReference($stj);
                if (in_array($status, CorePosOrderService::PROCESSED_STATUSES, true)) {
                    $this->audit($request, $auditUuid, $client->apc_id, $stj, 409, 'YA_PROCESADO', $startedAt, 'Pedido ya fue procesado.');

                    return response()->json(['ok' => false, 'message' => 'Pedido ya fue procesado'], 409);
                }

                $this->audit($request, $auditUuid, $client->apc_id, $stj, 404, 'NO_ENCONTRADO', $startedAt, 'Pedido no disponible para facturación.');

                return response()->json(['ok' => false, 'message' => 'Pedido no encontrado'], 404);
            }

            $this->audit($request, $auditUuid, $client->apc_id, $stj, 200, 'EXITOSO', $startedAt);

            return response()->json([
                'ok' => true,
                'data' => $data,
                'meta' => [
                    'api_version' => 'v1',
                    'request_id' => $auditUuid,
                    'generado_en' => now()->toIso8601String(),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $this->audit($request, $auditUuid, $client?->apc_id, $stj, 500, 'ERROR', $startedAt, 'Error interno.');

            return response()->json([
                'ok' => false,
                'message' => 'No fue posible consultar el pedido.',
                'request_id' => $auditUuid,
            ], 500);
        }
    }

    private function audit(Request $request, string $uuid, mixed $clientId, string $reference, int $status, string $result, int $startedAt, ?string $message = null): void
    {
        try {
            DB::table('stj_api_consultas')->insert([
                'aqc_uuid' => $uuid,
                'aqc_cliente_id' => $clientId,
                'aqc_endpoint' => '/api/v1/sv/billing/orders/{stj}',
                'aqc_metodo' => 'GET',
                'aqc_referencia' => Str::limit($reference, 100, ''),
                'aqc_pais_id' => 1,
                'aqc_ip' => $request->ip(),
                'aqc_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'aqc_http_estado' => $status,
                'aqc_resultado' => $result,
                'aqc_duracion_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'aqc_mensaje' => $message,
                'aqc_creado_en' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('No fue posible registrar la consulta de CorePOS.', [
                'request_id' => $uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
