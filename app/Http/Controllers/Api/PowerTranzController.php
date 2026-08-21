<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CartOperationConflict;
use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\Payments\PowerTranzPaymentService;
use App\Services\Payments\PowerTranzUrlFactory;
use App\Services\CheckoutEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PowerTranzController extends Controller
{
    public function start(Request $request, int $order, PowerTranzPaymentService $service, CheckoutEventService $events): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'card' => ['required', 'array'], 'card.pan' => ['required', 'digits_between:13,19'], 'card.cvv' => ['required', 'digits_between:3,4'], 'card.expiration' => ['required', 'digits:4'], 'card.holder' => ['required', 'string', 'max:100']]);
        $visitor = $this->visitor($request); $customer = $this->customer();
        $event = ['flow' => 'PAYMENT', 'stage' => 'POWERTRANZ_START', 'event' => 'PAYMENT_ATTEMPT_STARTED', 'result' => 'STARTED', 'provider' => 'POWERTRANZ', 'order_id' => $order, 'operation_uuid' => $data['operation_uuid'], 'payment_method' => 'TARJETA'];
        $events->record($request, $event, $visitor, $customer);
        try {
            $result = $service->start($order, $visitor, $customer, $data);
        } catch (CartOperationConflict $exception) {
            $events->record($request, array_merge($event, ['event' => 'POWERTRANZ_REQUEST_ERROR', 'result' => 'ERROR', 'severity' => 'WARNING', 'code' => 'OPERATION_CONFLICT', 'message' => $exception->getMessage(), 'http_status' => 409]), $visitor, $customer);
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        } catch (\Throwable $exception) {
            $events->record($request, array_merge($event, ['event' => 'POWERTRANZ_REQUEST_ERROR', 'result' => 'ERROR', 'severity' => 'ERROR', 'code' => class_basename($exception), 'message' => $events->exceptionMessage($exception)]), $visitor, $customer);
            throw $exception;
        }

        $events->record($request, array_merge($event, ['event' => match ($result['status'] ?? null) { 'PENDIENTE' => 'POWERTRANZ_CHALLENGE_REQUIRED', 'APROBADA' => 'POWERTRANZ_APPROVED', default => 'POWERTRANZ_DENIED' }, 'result' => match ($result['status'] ?? null) { 'PENDIENTE' => 'STARTED', 'APROBADA' => 'SUCCESS', default => 'REJECTED' }, 'severity' => ($result['status'] ?? null) === 'DENEGADA' ? 'WARNING' : 'INFO', 'payment_id' => $result['paymentId'] ?? null, 'provider_code' => $result['code'] ?? null]), $visitor, $customer);

        if (filled($result['redirectData'] ?? null)) {
            $challengeToken = Str::random(64);
            Cache::put('powertranz:challenge:'.hash('sha256', $challengeToken), (string) $result['redirectData'], now()->addMinutes(10));
            unset($result['redirectData']);
            $result['challengeUrl'] = route('powertranz.challenge', ['token' => $challengeToken]);
        }

        return response()->json(['ok' => true, 'message' => 'Flujo PowerTranz iniciado.', 'data' => $result])->header('Cache-Control', 'no-store, private');
    }

    public function challenge(string $token)
    {
        $html = Cache::get('powertranz:challenge:'.hash('sha256', $token));
        abort_unless(is_string($html) && $html !== '', 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function handleReturn(Request $request, string $country, string $token, PowerTranzPaymentService $service, PowerTranzUrlFactory $urls, CheckoutEventService $events): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['SpiToken' => ['required', 'string', 'max:10000'], 'TransactionIdentifier' => ['required', 'string', 'max:255'], 'Response' => ['required', 'string', 'max:10000']]);
        $event = ['country' => $country, 'flow' => 'PAYMENT', 'stage' => 'POWERTRANZ_RETURN', 'event' => 'POWERTRANZ_RETURN_RECEIVED', 'result' => 'STARTED', 'provider' => 'POWERTRANZ', 'operation_uuid' => $data['TransactionIdentifier']];
        $events->record($request, $event);
        try { $result = $service->confirm($country, $token, $data); }
        catch (\Throwable $exception) {
            $events->record($request, array_merge($event, ['event' => 'POWERTRANZ_RETURN_ERROR', 'result' => 'ERROR', 'severity' => 'ERROR', 'code' => class_basename($exception), 'message' => $events->exceptionMessage($exception)]));
            throw $exception;
        }
        $payment = DB::table('stj_pedidos_pago')->where('ppa_id', $result['paymentId'])->first(['ppa_rsp_codigo', 'ppa_rsp_mensaje', 'ppa_monto']);
        $events->record($request, array_merge($event, ['event' => $result['status'] === 'APROBADA' ? 'POWERTRANZ_APPROVED' : 'POWERTRANZ_DENIED', 'result' => $result['status'] === 'APROBADA' ? 'SUCCESS' : 'REJECTED', 'severity' => $result['status'] === 'APROBADA' ? 'INFO' : 'WARNING', 'order_id' => $result['orderId'], 'payment_id' => $result['paymentId'], 'amount' => $payment?->ppa_monto, 'provider_code' => $payment?->ppa_rsp_codigo, 'provider_message' => $payment?->ppa_rsp_mensaje]));
        $frontend = trim((string) config('powertranz.frontend_result_url'));
        if ($frontend !== '') {
            return redirect()->away($urls->frontendResultUrl($country, $result['status']))->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
        }

        return response()->json(['ok' => true, 'data' => ['status' => $result['status']]])->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function status(Request $request, int $order): JsonResponse
    {
        $visitor = $this->visitor($request);
        $customer = $this->customer();
        $cart = DB::table('stj_carritos')->where('car_pedido_id', $order)->where('car_visitante_id', $visitor->getKey())->when($customer, fn ($q) => $q->where('car_usu_id', $customer->getKey()), fn ($q) => $q->whereNull('car_usu_id'))->first();
        abort_unless($cart, 404);
        $payment = DB::table('stj_pedidos_pago')->where('ppa_pedido', $order)->orderByDesc('ppa_id')->first(['ppa_tipo', 'ppa_estado', 'ppa_ref', 'ppa_autorizacion', 'ppa_rsp_codigo', 'ppa_rsp_mensaje', 'ppa_fecha_procesado']);

        return response()->json(['ok' => true, 'data' => ['orderId' => $order, 'paymentType' => $payment?->ppa_tipo, 'status' => $payment?->ppa_estado ?? 'PENDIENTE', 'reference' => $payment?->ppa_ref, 'authorization' => $payment?->ppa_autorizacion, 'responseCode' => $payment?->ppa_rsp_codigo, 'responseMessage' => $payment?->ppa_rsp_mensaje, 'processedAt' => $payment?->ppa_fecha_procesado]])->header('Cache-Control', 'no-store, private');
    }

    private function visitor(Request $request): StorefrontVisitor
    {
        return $request->attributes->get('storefrontVisitor');
    }

    private function customer(): ?StorefrontCustomer
    {
        $user = Auth::guard('sanctum')->user();

        return $user instanceof StorefrontCustomer ? $user : null;
    }
}
