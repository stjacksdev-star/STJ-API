<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutEventService
{
    private const SENSITIVE_KEYS = ['pan', 'cvv', 'card', 'document', 'phone', 'address', 'spitoken', 'redirectdata', 'password'];

    public function record(Request $request, array $event, ?StorefrontVisitor $visitor = null, ?StorefrontCustomer $customer = null): void
    {
        try {
            if (! Schema::hasTable('stj_checkout_eventos')) {
                return;
            }

            $countryId = $event['country_id'] ?? $this->countryId($event['country'] ?? null);
            $cart = $this->cart($visitor, $customer, $countryId, $event['cart_id'] ?? null, $event['order_id'] ?? null);
            $orderId = $this->positiveInt($event['order_id'] ?? $cart?->car_pedido_id);
            $paymentId = $this->positiveInt($event['payment_id'] ?? null);
            if (! $paymentId && $orderId) {
                $paymentId = $this->positiveInt(DB::table('stj_pedidos_pago')->where('ppa_pedido', $orderId)->orderByDesc('ppa_id')->value('ppa_id'));
            }

            DB::table('stj_checkout_eventos')->insert([
                'coe_uuid' => (string) ($event['uuid'] ?? Str::uuid()),
                'coe_pais_id' => $countryId,
                'coe_carrito_id' => $this->positiveInt($cart?->car_id ?? $event['cart_id'] ?? null),
                'coe_pedido_id' => $orderId,
                'coe_pago_id' => $paymentId,
                'coe_visitante_id' => $this->positiveInt($visitor?->getKey()),
                'coe_usu_id' => $this->positiveInt($customer?->getKey()),
                'coe_request_id' => $this->text($event['request_id'] ?? $request->header('X-Request-ID'), 100),
                'coe_operacion_uuid' => $this->uuidOrNull($event['operation_uuid'] ?? null),
                'coe_session_hash' => $this->hash($request->cookie('stj_visitor')),
                'coe_flujo' => $this->text($event['flow'] ?? 'CHECKOUT', 32) ?? 'CHECKOUT',
                'coe_etapa' => $this->text($event['stage'] ?? 'UNKNOWN', 50) ?? 'UNKNOWN',
                'coe_evento' => $this->text($event['event'] ?? 'UNKNOWN', 80) ?? 'UNKNOWN',
                'coe_resultado' => $this->text($event['result'] ?? 'ERROR', 20) ?? 'ERROR',
                'coe_severidad' => $this->text($event['severity'] ?? 'INFO', 16) ?? 'INFO',
                'coe_checkout_tipo' => $this->text($event['checkout_type'] ?? $cart?->car_tipo, 20),
                'coe_metodo_pago' => $this->text($event['payment_method'] ?? null, 20),
                'coe_moneda' => $this->text($event['currency'] ?? $cart?->car_moneda, 3),
                'coe_monto' => isset($event['amount']) && is_numeric($event['amount']) ? round((float) $event['amount'], 2) : null,
                'coe_tienda_codigo' => $this->text($event['store_code'] ?? null, 20),
                'coe_codigo' => $this->text($event['code'] ?? null, 100),
                'coe_mensaje' => $this->text($event['message'] ?? null, 1000),
                'coe_http_status' => $this->httpStatus($event['http_status'] ?? null),
                'coe_proveedor' => $this->text($event['provider'] ?? null, 32),
                'coe_proveedor_codigo' => $this->text($event['provider_code'] ?? null, 100),
                'coe_proveedor_mensaje' => $this->text($event['provider_message'] ?? null, 1000),
                'coe_metadata' => json_encode($this->sanitize($event['metadata'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'coe_origen' => $this->text($event['origin'] ?? 'WEB', 20) ?? 'WEB',
                'coe_ruta' => $this->text($request->path(), 255),
                'coe_ip_hash' => $this->hash($request->ip()),
                'coe_user_agent_hash' => $this->hash($request->userAgent()),
                'coe_ocurrido_en' => now(),
                'coe_recibido_en' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('No fue posible registrar evento de checkout.', ['event' => $event['event'] ?? null, 'error' => $exception->getMessage()]);
        }
    }

    public function exceptionMessage(\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();
            if (is_string($message) && trim($message) !== '') return $message;
        }
        return 'Error interno durante el procesamiento. Consulta los logs con el UUID de la operacion.';
    }

    private function cart(?StorefrontVisitor $visitor, ?StorefrontCustomer $customer, ?int $countryId, mixed $cartId, mixed $orderId): ?object
    {
        if (! $visitor) return null;
        return DB::table('stj_carritos')->where('car_visitante_id', $visitor->getKey())
            ->when($customer, fn ($q) => $q->where('car_usu_id', $customer->getKey()), fn ($q) => $q->whereNull('car_usu_id'))
            ->when($this->positiveInt($cartId), fn ($q, $id) => $q->where('car_id', $id))
            ->when(! $this->positiveInt($cartId) && $this->positiveInt($orderId), fn ($q) => $q->where('car_pedido_id', $this->positiveInt($orderId)))
            ->when(! $this->positiveInt($cartId) && ! $this->positiveInt($orderId) && $countryId, fn ($q) => $q->where('car_pais_id', $countryId))
            ->orderByDesc('car_id')->first();
    }

    private function countryId(mixed $country): ?int
    {
        $code = strtoupper(trim((string) $country));
        return $code === '' ? null : $this->positiveInt(DB::table('stj_paises')->where('pai_codigo', $code)->value('pai_id'));
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 3) return null;
        if (is_array($value)) {
            $clean = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) continue;
                $clean[$this->text($key, 80) ?? 'value'] = $this->sanitize($item, $depth + 1);
            }
            return $clean;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
        return $this->text($value, 500);
    }

    private function text(mixed $value, int $limit): ?string { $value = trim((string) $value); return $value === '' ? null : mb_substr($value, 0, $limit); }
    private function hash(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key')); }
    private function positiveInt(mixed $value): ?int { return is_numeric($value) && (int) $value > 0 ? (int) $value : null; }
    private function uuidOrNull(mixed $value): ?string { return is_string($value) && Str::isUuid($value) ? $value : null; }
    private function httpStatus(mixed $value): ?int { return is_numeric($value) && (int) $value >= 100 && (int) $value <= 599 ? (int) $value : null; }
}
