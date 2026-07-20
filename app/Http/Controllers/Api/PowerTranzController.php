<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CartOperationConflict;
use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\Payments\PowerTranzPaymentService;
use App\Services\Payments\PowerTranzUrlFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PowerTranzController extends Controller
{
    public function start(Request $request, int $order, PowerTranzPaymentService $service): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'card' => ['required', 'array'], 'card.pan' => ['required', 'digits_between:13,19'], 'card.cvv' => ['required', 'digits_between:3,4'], 'card.expiration' => ['required', 'digits:4'], 'card.holder' => ['required', 'string', 'max:100']]);
        try {
            $result = $service->start($order, $this->visitor($request), $this->customer(), $data);
        } catch (CartOperationConflict $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'message' => 'Flujo PowerTranz iniciado.', 'data' => $result])->header('Cache-Control', 'no-store, private');
    }

    public function handleReturn(Request $request, string $country, string $token, PowerTranzPaymentService $service, PowerTranzUrlFactory $urls): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['SpiToken' => ['required', 'string', 'max:10000'], 'TransactionIdentifier' => ['required', 'string', 'max:255'], 'Response' => ['required', 'string', 'max:10000']]);
        $result = $service->confirm($country, $token, $data);
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
        $payment = DB::table('stj_pedidos_pago')->where('ppa_pedido', $order)->orderByDesc('ppa_id')->first(['ppa_tipo', 'ppa_estado', 'ppa_ref', 'ppa_autorizacion', 'ppa_fecha_procesado']);

        return response()->json(['ok' => true, 'data' => ['orderId' => $order, 'paymentType' => $payment?->ppa_tipo, 'status' => $payment?->ppa_estado ?? 'PENDIENTE', 'reference' => $payment?->ppa_ref, 'authorization' => $payment?->ppa_autorizacion, 'processedAt' => $payment?->ppa_fecha_procesado]])->header('Cache-Control', 'no-store, private');
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
