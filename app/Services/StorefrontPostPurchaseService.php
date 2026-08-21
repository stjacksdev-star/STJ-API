<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StorefrontPostPurchaseService
{
    public function __construct(
        private readonly StorefrontOrderConfirmationEmailService $email,
        private readonly StorefrontPostPurchaseIntegrationService $integration,
    ) {}

    public function schedule(int $orderId, int $paymentId): void
    {
        DB::afterCommit(fn () => $this->run($orderId, $paymentId));
    }

    public function run(int $orderId, int $paymentId): void
    {
        try {
            $this->email->send($orderId, $paymentId);
        } catch (\Throwable $exception) {
            Log::error('No fue posible enviar la confirmación de compra por SMTP2GO.', ['order_id' => $orderId, 'payment_id' => $paymentId, 'exception' => $exception->getMessage()]);
        }

        try {
            $this->integration->dispatch($orderId, $paymentId);
        } catch (\Throwable $exception) {
            Log::error('No fue posible ejecutar la integración post-compra.', ['order_id' => $orderId, 'payment_id' => $paymentId, 'exception' => $exception->getMessage()]);
        }
    }
}
