<?php

namespace Tests\Feature;

use App\Services\Payments\PowerTranzClient;
use App\Services\Payments\PowerTranzConfigResolver;
use App\Services\Payments\PowerTranzRefundService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PowerTranzRefundServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', function (Blueprint $table) { $table->id('pai_id'); $table->string('pai_codigo'); });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->id('ped_id'); $table->unsignedBigInteger('ped_id_pais'); $table->string('ped_origen');
            $table->string('ped_devolucion_realizada'); $table->decimal('ped_monto_devolucion', 10, 2);
            $table->dateTime('ped_fecha_devolucion')->nullable(); $table->dateTime('ped_fecha_devolucion_sistema')->nullable();
            $table->text('ped_rsp_servicio')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->id('ppa_id'); $table->unsignedBigInteger('ppa_pedido'); $table->string('ppa_estado');
            $table->string('ppa_ref'); $table->decimal('ppa_monto', 10, 2); $table->string('ppa_transactionidentifier')->nullable();
        });
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
    }

    public function test_approved_app_refund_uses_app_origin_and_marks_order_processed(): void
    {
        $orderId = $this->pendingOrder('APP', 'TX-APP');
        $config = Mockery::mock(PowerTranzConfigResolver::class);
        $config->shouldReceive('forCountry')->once()->with('sv', 'APP')->andReturn($this->configuration());
        $client = Mockery::mock(PowerTranzClient::class);
        $client->shouldReceive('refund')->once()->withArgs(function ($configuration, $payload) {
            return $configuration['id'] === 'app-id' && $payload['Refund'] === true
                && $payload['TransactionIdentifier'] === 'TX-APP' && $payload['TotalAmount'] === 12.34
                && $payload['CurrencyCode'] === '840';
        })->andReturn(['Approved' => true, 'TransactionIdentifier' => 'TX-APP', 'IsoResponseCode' => '00']);

        $result = (new PowerTranzRefundService($config, $client))->process($orderId);

        $this->assertSame('APROBADA', $result['status']);
        $this->assertDatabaseHas('stj_pedidos', ['ped_id' => $orderId, 'ped_devolucion_realizada' => 'SI']);
        $this->assertNotNull(DB::table('stj_pedidos')->where('ped_id', $orderId)->value('ped_fecha_devolucion_sistema'));
    }

    public function test_rejected_refund_stays_pending_and_persists_gateway_response(): void
    {
        $orderId = $this->pendingOrder('WEB', 'TX-WEB');
        $config = Mockery::mock(PowerTranzConfigResolver::class);
        $config->shouldReceive('forCountry')->with('sv', 'WEB')->andReturn($this->configuration());
        $client = Mockery::mock(PowerTranzClient::class);
        $client->shouldReceive('refund')->andReturn(['Approved' => false, 'IsoResponseCode' => '05']);

        $result = (new PowerTranzRefundService($config, $client))->process($orderId);

        $this->assertSame('RECHAZADA', $result['status']);
        $order = DB::table('stj_pedidos')->where('ped_id', $orderId)->first();
        $this->assertSame('NO', $order->ped_devolucion_realizada);
        $this->assertSame('05', json_decode($order->ped_rsp_servicio, true)['IsoResponseCode']);
    }

    public function test_historical_payment_without_transaction_identifier_is_not_eligible(): void
    {
        $orderId = $this->pendingOrder('WEB', null);
        $service = new PowerTranzRefundService(Mockery::mock(PowerTranzConfigResolver::class), Mockery::mock(PowerTranzClient::class));

        $this->assertSame([], $service->pendingOrderIds());
        $this->expectExceptionMessage('no tiene ppa_transactionidentifier');
        $service->process($orderId);
    }

    public function test_manual_identifier_accepts_reference_or_order_id(): void
    {
        $orderId = $this->pendingOrder('WEB', 'TX-WEB');
        $service = new PowerTranzRefundService(Mockery::mock(PowerTranzConfigResolver::class), Mockery::mock(PowerTranzClient::class));
        $this->assertSame($orderId, $service->resolveOrderId((string) $orderId));
        $this->assertSame($orderId, $service->resolveOrderId('STJ-TEST'));
    }

    private function pendingOrder(string $origin, ?string $transaction): int
    {
        $orderId = DB::table('stj_pedidos')->insertGetId(['ped_id_pais' => 1, 'ped_origen' => $origin,
            'ped_devolucion_realizada' => 'NO', 'ped_monto_devolucion' => 12.34, 'ped_fecha_devolucion' => now()]);
        DB::table('stj_pedidos_pago')->insert(['ppa_pedido' => $orderId, 'ppa_estado' => 'APROBADA',
            'ppa_ref' => 'STJ-TEST', 'ppa_monto' => 50, 'ppa_transactionidentifier' => $transaction]);
        return $orderId;
    }

    private function configuration(): array
    {
        return ['id' => 'app-id', 'password' => 'secret', 'currency' => '840', 'refund_url' => 'https://staging.ptranz.com/api/refund',
            'connect_timeout' => 1, 'timeout' => 2];
    }
}
