<?php

namespace Tests\Feature;

use App\Services\Prism\PrismShipmentProcessor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PrismShipmentProcessorTest extends TestCase
{
    private ?array $document = null;

    private array $items = [];

    private array $tenders = [];

    private ?array $customer = null;

    private array $contacts = [];

    private array $mutations = [];

    private ?string $failure = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'sqlite') {
            throw new RuntimeException('Tests require testing and SQLite.');
        }
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        Schema::create('stj_paises', function (Blueprint $t) {
            $t->integer('pai_id')->primary();
            $t->string('pai_codigo');
        });
        Schema::create('stj_pedidos', function (Blueprint $t) {
            $t->integer('ped_id')->primary();
            $t->integer('ped_id_pais');
            foreach (['ped_tienda', 'ped_checkout', 'ped_identificacion', 'ped_email', 'ped_nombres', 'ped_apellidos', 'ped_direccion', 'ped_telefono'] as $field) {
                $t->string($field);
            }
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $t) {
            $t->integer('ppa_id')->primary();
            $t->integer('ppa_pedido');
            foreach (['ppa_estado', 'ppa_ref', 'ppa_tipo', 'ppa_autorizacion', 'ppa_tarjeta', 'ppa_emisor'] as $field) {
                $t->string($field);
            }
            $t->decimal('ppa_monto', 12, 2);
            $t->decimal('ppa_monto_senv', 12, 2);
        });
        Schema::create('stj_tiendas', function (Blueprint $t) {
            $t->id('tie_id');
            $t->integer('tie_pais');
            $t->string('tie_codigo');
            $t->string('prism_sid');
            $t->string('prism_store_number');
        });
        Schema::create('stj_productos', function (Blueprint $t) {
            $t->integer('pro_id')->primary();
            $t->string('pro_codigo');
        });
        Schema::create('stj_pedidos_detalle', function (Blueprint $t) {
            $t->id('car_id');
            $t->integer('car_producto');
            $t->integer('car_pais');
            $t->integer('car_cantidad');
            foreach (['car_ref', 'car_accion', 'car_estilo_final', 'car_talla_final', 'car_talla'] as $field) {
                $t->string($field);
            }
            $t->decimal('car_precio', 12, 2);
            $t->decimal('car_descuento', 8, 2);
            $t->decimal('car_descuento_final', 8, 2);
        });
        Schema::create('prism_envios', function (Blueprint $t) {
            $t->id('pe_id');
            foreach (['ped_id', 'ppa_id', 'pais_codigo', 'intentos'] as $field) {
                $t->integer($field);
            }
            foreach (['stj_ref', 'tienda_codigo', 'tienda_sid', 'customer_sid', 'document_sid', 'customer_row_version',
                'document_row_version', 'status', 'error_code', 'error_message', 'integration_environment'] as $field) {
                $t->string($field)->nullable();
            }
            $t->text('processing_checkpoint')->nullable();
            $t->dateTime('last_try_at')->nullable();
            $t->timestamps();
            $t->unique(['pais_codigo', 'stj_ref']);
        });
        Schema::create('prism_envios_log', function (Blueprint $t) {
            $t->id('pel_id');
            $t->integer('pe_id');
            $t->string('step');
            $t->integer('http_code')->nullable();
            $t->string('request_url')->nullable();
            $t->text('request_payload')->nullable();
            $t->text('response_body')->nullable();
            $t->text('error_message')->nullable();
            $t->dateTime('created_at');
        });
        config(['prism.hn.host' => 'http://prism.example', 'prism.hn.username' => 'user', 'prism.hn.password' => 'secret',
            'prism.hn.workstation' => 'ws', 'prism.hn.sku_url' => 'https://sku.example/lookup', 'prism.hn.sku_token' => 'sku-secret',
            'prism.hn.tenant_sid' => '100', 'prism.hn.subsidiary_sid' => '101', 'prism.hn.subsidiary_number' => 1,
            'storefront_post_purchase.integrations_enabled' => true, 'storefront_post_purchase.honduras.enabled' => true]);
        DB::table('stj_paises')->insert([['pai_id' => 7, 'pai_codigo' => 'HN'], ['pai_id' => 1, 'pai_codigo' => 'SV']]);
        DB::table('stj_pedidos')->insert(['ped_id' => 10, 'ped_id_pais' => 7, 'ped_tienda' => '002', 'ped_checkout' => 'TIENDA',
            'ped_identificacion' => '0801-1234', 'ped_email' => 'client@example.test', 'ped_nombres' => 'Test', 'ped_apellidos' => 'Client',
            'ped_direccion' => 'Direccion de prueba', 'ped_telefono' => '99999999']);
        DB::table('stj_pedidos_pago')->insert(['ppa_id' => 20, 'ppa_pedido' => 10, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'STJ-100',
            'ppa_tipo' => 'EFECTIVO', 'ppa_autorizacion' => 'AUTH-123', 'ppa_tarjeta' => 'VISA', 'ppa_emisor' => 'BAC',
            'ppa_monto' => 90, 'ppa_monto_senv' => 90]);
        DB::table('stj_tiendas')->insert([
            ['tie_pais' => 7, 'tie_codigo' => '002', 'prism_sid' => '761346871000100960', 'prism_store_number' => '2'],
            ['tie_pais' => 1, 'tie_codigo' => '002', 'prism_sid' => '123', 'prism_store_number' => '2'],
        ]);
        DB::table('stj_productos')->insert(['pro_id' => 1, 'pro_codigo' => 'SKU1']);
        DB::table('stj_pedidos_detalle')->insert(['car_producto' => 1, 'car_pais' => 7, 'car_cantidad' => 1,
            'car_ref' => 'STJ-100', 'car_accion' => 'AGREGADO', 'car_estilo_final' => 'SKU1', 'car_talla_final' => 'M',
            'car_talla' => 'M', 'car_precio' => 100, 'car_descuento' => 10, 'car_descuento_final' => 10]);
        DB::table('prism_envios')->insert(['pe_id' => 1, 'ped_id' => 10, 'ppa_id' => 20, 'pais_codigo' => 7,
            'stj_ref' => 'STJ-100', 'tienda_codigo' => '002', 'tienda_sid' => '761346871000100960',
            'status' => 'pendiente', 'intentos' => 0, 'integration_environment' => 'testing', 'created_at' => now(), 'updated_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => $this->remote($request));
    }

    private function remote($request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        if ($path === '/api/security/login') {
            return Http::response([['token' => 'token']]);
        }
        if ($path === '/api/security/logout') {
            return Http::response('');
        }
        if ($path === '/lookup') {
            return Http::response(['RESULTADO' => true, 'datos' => $this->failure === 'sku_missing' ? [] : [
                ['sku_art' => 'SKU1-M', 'estilo' => 'SKU1', 'talla' => 'M', 'sid' => '760000000000000001'],
            ]]);
        }
        if (str_starts_with($path, '/v1/rest/store/')) {
            return Http::response([['sid' => '761346871000100960', 'store_code' => '002', 'store_number' => '2', 'subsidiary_sid' => '101', 'active' => true]]);
        }
        if ($method !== 'GET') {
            $this->mutations[] = $method.' '.$path;
        }
        if ($path === '/v1/rest/document/') {
            if ($method === 'GET') {
                return Http::response($this->document ? [$this->document] : []);
            }
            $this->document = array_merge($request->data()[0], ['sid' => '790000000000000001', 'row_version' => 1, 'transaction_total_amt' => 0]);
            if ($this->failure === 'document_timeout') {
                $this->failure = null;
                throw new ConnectionException('Timeout after create');
            }

            return Http::response([$this->document]);
        }
        if ($path === '/v1/rest/document/790000000000000001') {
            if ($method === 'PUT') {
                $this->document = array_merge($this->document, $request->data()[0]);
                $this->document['row_version']++;
                if ($this->failure === 'hold_timeout' && ($request->data()[0]['status'] ?? null) === 4) {
                    $this->failure = null;
                    throw new ConnectionException('Timeout after final status');
                }
            }

            return Http::response([$this->document]);
        }
        if (str_ends_with($path, '/item')) {
            if ($method === 'POST') {
                $this->items = $request->data();
                if ($this->failure === 'normalized_discount') {
                    foreach ($this->items as &$item) {
                        $item['discount_amt'] = $item['manual_disc_value'];
                        $item['manual_disc_value'] = null;
                        $item['manual_disc_type'] = null;
                    }
                    unset($item);
                }
                $this->document['transaction_total_amt'] = $this->failure === 'wrong_total' ? 100 : 90;
                $this->document['row_version']++;
                if ($this->failure === 'items_timeout') {
                    $this->failure = null;
                    throw new ConnectionException('Timeout after items');
                }
            }

            return Http::response($this->items);
        }
        if (str_ends_with($path, '/tender')) {
            if ($method === 'POST') {
                $this->tenders = $request->data();
                $this->document['row_version']++;
                if ($this->failure === 'tender_timeout') {
                    $this->failure = null;
                    throw new ConnectionException('Timeout after tender');
                }
            }

            return Http::response($this->tenders);
        }
        if ($path === '/v1/rest/customer' && $method === 'POST') {
            $this->customer = array_merge($request->data()[0], ['sid' => '780000000000000001', 'row_version' => 1,
                'emails' => [], 'addresses' => [], 'phones' => []]);

            return Http::response([$this->customer]);
        }
        if ($path === '/v1/rest/customer/' || $path === '/v1/rest/customer/780000000000000001') {
            return Http::response($this->customer ? [$this->customer] : []);
        }
        if (isset($this->contacts[$path])) {
            if ($method === 'PUT') {
                $this->contacts[$path] = array_merge($this->contacts[$path], $request->data()[0]);
                $this->contacts[$path]['row_version']++;
            }

            return Http::response([$this->contacts[$path]]);
        }
        throw new RuntimeException('Unexpected test request '.$method.' '.$path);
    }

    public function test_cash_creates_verified_document_without_tender_and_second_execution_is_noop(): void
    {
        $result = app(PrismShipmentProcessor::class)->process(1, true);
        $this->assertSame('enviado', $result['status']);
        $this->assertSame(4, $this->document['status']);
        $this->assertSame([], $this->tenders);
        $this->assertDatabaseHas('prism_envios', ['pe_id' => 1, 'status' => 'enviado', 'intentos' => 1, 'document_sid' => '790000000000000001']);
        $before = $this->mutations;
        $this->assertSame('ya_enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertSame($before, $this->mutations);
        $logins = Http::recorded(fn ($request) => str_contains($request->url(), '/api/security/login'));
        $this->assertCount(1, $logins);
    }

    public function test_card_posts_exact_approved_amount_and_deposit(): void
    {
        DB::table('stj_pedidos_pago')->update(['ppa_tipo' => 'TARJETA']);
        app(PrismShipmentProcessor::class)->process(1, true);
        $this->assertEquals(90, $this->tenders[0]['taken']);
        $this->assertSame('AUTH-123', $this->tenders[0]['authorization_code']);
        $this->assertEquals(90, $this->document['so_deposit_amt_paid']);
        $this->assertSame(4, $this->document['status']);

        $payloads = DB::table('prism_envios_log')->whereNotNull('request_payload')
            ->pluck('request_payload', 'step')->map(fn ($payload) => json_decode($payload, true))->all();
        $this->assertSame('STJ-100', $payloads['document_create'][0]['order_tracking_number']);
        $this->assertSame('760000000000000001', (string) $payloads['items_post'][0]['invn_sbs_item_sid']);
        $this->assertSame(2, $payloads['items_post'][0]['manual_disc_type']);
        $this->assertSame('08011234', $payloads['customer_create'][0]['info1']);
        $this->assertSame('AUTH-123', $payloads['tender_post'][0]['authorization_code']);
        $this->assertEquals(90, $payloads['deposit_put'][0]['so_deposit_amt_paid']);
        $this->assertSame(4, $payloads['document_hold'][0]['status']);
    }

    public function test_cash_payload_omits_manual_discount_type_when_discount_is_zero(): void
    {
        DB::table('stj_pedidos_detalle')->update(['car_precio' => 90, 'car_descuento' => 0, 'car_descuento_final' => 40]);

        app(PrismShipmentProcessor::class)->process(1, true);

        $payload = json_decode((string) DB::table('prism_envios_log')
            ->where('step', 'items_post')->where('error_message', 'START')->value('request_payload'), true);
        $this->assertEquals(0, $payload[0]['manual_disc_value']);
        $this->assertArrayNotHasKey('manual_disc_type', $payload[0]);
        $this->assertNull(DB::table('prism_envios_log')->where('step', 'document_get')->value('request_payload'));
    }

    public function test_default_command_only_validates_with_switches_off(): void
    {
        config(['storefront_post_purchase.integrations_enabled' => false, 'storefront_post_purchase.honduras.enabled' => false]);
        $this->artisan('prism:process-shipment --stj=STJ-100')->assertSuccessful();
        $this->assertSame([], $this->mutations);
        $this->assertDatabaseHas('prism_envios', ['status' => 'pendiente', 'intentos' => 0, 'processing_checkpoint' => null]);
        $this->assertDatabaseCount('prism_envios_log', 0);
    }

    public function test_execution_requires_switches_and_exact_confirmation(): void
    {
        $this->artisan('prism:process-shipment --shipment=1 --execute')->assertFailed();
        config(['storefront_post_purchase.honduras.enabled' => false]);
        $this->artisan('prism:process-shipment --shipment=1 --execute --confirm-ref=STJ-100')->assertFailed();
        $this->assertSame([], $this->mutations);
        Http::assertNothingSent();
    }

    public function test_command_accepts_order_payment_pair_and_rejects_mixed_selectors(): void
    {
        $this->artisan('prism:process-shipment --order=10 --payment=20')->assertSuccessful();
        $this->artisan('prism:process-shipment --order=10')->assertFailed();
        $this->artisan('prism:process-shipment --order=10 --payment=20 --stj=STJ-100')->assertFailed();
        $this->artisan('prism:process-shipment --order=10 --payment=21')->assertFailed();
    }

    public function test_environment_and_country_are_rechecked_before_http(): void
    {
        DB::table('prism_envios')->update(['integration_environment' => 'production']);
        $this->assertFailure('APP_ENV');
        DB::table('prism_envios')->update(['integration_environment' => 'testing']);
        DB::table('stj_pedidos')->update(['ped_id_pais' => 1]);
        $this->assertFailure('Honduras');
        Http::assertNothingSent();
    }

    public function test_missing_skus_stop_before_any_retail_write(): void
    {
        $this->failure = 'sku_missing';
        $this->assertFailure('faltan artículos');
        $this->assertSame([], $this->mutations);
        $this->assertDatabaseHas('prism_envios', ['status' => 'error']);
    }

    public function test_retail_calculated_total_is_used_instead_of_local_reference(): void
    {
        DB::table('stj_pedidos_pago')->update(['ppa_tipo' => 'TARJETA']);
        $this->failure = 'wrong_total';
        $result = app(PrismShipmentProcessor::class)->process(1, true);
        $this->assertSame('enviado', $result['status']);
        $this->assertEquals(100, $this->tenders[0]['taken']);
        $this->assertEquals(100, $this->document['so_deposit_amt_paid']);
    }

    public function test_card_freight_is_excluded_from_document_tender_and_deposit(): void
    {
        DB::table('stj_pedidos_pago')->update(['ppa_tipo' => 'TARJETA', 'ppa_monto' => 100]);
        DB::table('stj_pedidos')->update(['ped_checkout' => 'DOMICILIO']);
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertEquals(90, $this->document['transaction_total_amt']);
        $this->assertEquals(90, $this->document['so_deposit_amt_paid']);
        $this->assertEquals(90, $this->tenders[0]['taken']);
        $this->assertCount(1, $this->items);
        $this->assertDatabaseHas('stj_pedidos_pago', ['ppa_monto' => 100, 'ppa_monto_senv' => 90]);
    }

    public function test_cash_also_uses_products_subtotal_without_freight(): void
    {
        DB::table('stj_pedidos_pago')->update(['ppa_monto' => 100]);
        app(PrismShipmentProcessor::class)->process(1, true);
        $this->assertEquals(90, $this->document['transaction_total_amt']);
        $this->assertSame([], $this->tenders);
    }

    public function test_null_manual_discount_uses_persisted_zero_discount(): void
    {
        DB::table('stj_pedidos_detalle')->update(['car_precio' => 90, 'car_descuento' => 0, 'car_descuento_final' => 0]);
        $this->failure = 'normalized_discount';
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertNull($this->items[0]['manual_disc_value']);
        $this->assertEquals(0, $this->items[0]['discount_amt']);
    }

    public function test_persisted_positive_discount_is_verified_when_manual_fields_are_null(): void
    {
        $this->failure = 'normalized_discount';
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertEquals(10, $this->items[0]['discount_amt']);
    }

    public function test_existing_uncertain_items_with_null_manual_discount_resume_without_reposting(): void
    {
        DB::table('stj_pedidos_detalle')->update(['car_precio' => 90, 'car_descuento' => 0, 'car_descuento_final' => 0]);
        $this->failure = 'items_timeout';
        $this->assertFailure('conexión');
        $this->items[0]['manual_disc_value'] = null;
        $this->items[0]['manual_disc_type'] = null;
        $this->items[0]['discount_amt'] = 0;
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertSame(1, count(array_filter($this->mutations, fn ($s) => str_ends_with($s, '/item'))));
        $this->assertSame(1, count(array_filter($this->mutations, fn ($s) => $s === 'POST /v1/rest/document/')));
    }

    public function test_tender_timeout_reconciles_without_duplicate_post(): void
    {
        DB::table('stj_pedidos_pago')->update(['ppa_tipo' => 'TARJETA']);
        $this->failure = 'tender_timeout';
        $this->assertFailure('conexión');
        $this->assertDatabaseHas('prism_envios', ['status' => 'error', 'error_code' => 'RECONCILE_REQUIRED']);
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertSame(1, count(array_filter($this->mutations, fn ($s) => $s === 'POST /v1/rest/document/790000000000000001/tender')));
    }

    public function test_document_timeout_recovers_sid_by_reference(): void
    {
        $this->failure = 'document_timeout';
        $this->assertFailure('conexión');
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertSame(1, count(array_filter($this->mutations, fn ($s) => $s === 'POST /v1/rest/document/')));
    }

    public function test_items_timeout_recovers_without_duplicate_articles(): void
    {
        $this->failure = 'items_timeout';
        $this->assertFailure('conexión');
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertSame(1, count($this->items));
        $this->assertSame(1, count(array_filter($this->mutations, fn ($s) => str_ends_with($s, '/item'))));
    }

    public function test_final_status_timeout_is_reconciled_without_more_writes(): void
    {
        $this->failure = 'hold_timeout';
        $this->assertFailure('conexión');
        $before = $this->mutations;
        $this->assertSame('enviado', app(PrismShipmentProcessor::class)->process(1, true)['status']);
        $this->assertSame($before, $this->mutations);
    }

    public function test_missing_result_after_ambiguous_create_is_not_repeated(): void
    {
        $this->failure = 'document_timeout';
        $this->assertFailure('conexión');
        $this->document = null;
        $before = $this->mutations;
        $this->assertFailure('no se repetirá');
        $this->assertSame($before, $this->mutations);
    }

    public function test_busy_shipment_cannot_be_claimed(): void
    {
        DB::table('prism_envios')->update(['status' => 'procesando']);
        $this->assertFailure('ocupado');
        Http::assertNothingSent();
    }

    public function test_primary_email_updates_its_own_link_not_the_first_contact(): void
    {
        $first = '/v1/rest/customer/780000000000000001/email/1';
        $primary = '/v1/rest/customer/780000000000000001/email/2';
        $this->customer = ['sid' => '780000000000000001', 'row_version' => 1, 'info1' => '08011234',
            'tenant_sid' => '100', 'subsidiary_sid' => '101', 'emails' => [['link' => $first], ['link' => $primary]]];
        $this->contacts = [
            $first => ['sid' => '1', 'row_version' => 1, 'primary_flag' => false, 'email_address' => 'secondary@example.test'],
            $primary => ['sid' => '2', 'row_version' => 2, 'primary_flag' => true, 'email_address' => 'old@example.test'],
        ];
        app(PrismShipmentProcessor::class)->process(1, true);
        $this->assertSame('secondary@example.test', $this->contacts[$first]['email_address']);
        $this->assertSame('client@example.test', $this->contacts[$primary]['email_address']);
        $this->assertNotContains('PUT '.$first, $this->mutations);
        $this->assertContains('PUT '.$primary, $this->mutations);
    }

    public function test_partial_items_are_not_reposted_on_retry(): void
    {
        $this->failure = 'items_timeout';
        $this->assertFailure('conexión');
        $this->items[0]['quantity'] = 2;
        $before = $this->mutations;
        $this->assertFailure('Artículos remotos no coinciden');
        $this->assertSame($before, $this->mutations);
    }

    public function test_changed_order_is_rejected_after_partial_execution(): void
    {
        $this->failure = 'items_timeout';
        $this->assertFailure('conexión');
        DB::table('stj_pedidos')->update(['ped_telefono' => '88888888']);
        $before = $this->mutations;
        $this->assertFailure('cambiaron después');
        $this->assertSame($before, $this->mutations);
    }

    public function test_customer_from_another_subsidiary_stops_before_document_creation(): void
    {
        $this->customer = ['sid' => '780000000000000001', 'info1' => '08011234', 'tenant_sid' => '100', 'subsidiary_sid' => '999'];
        $this->assertFailure('subsidiaria');
        $this->assertSame([], $this->mutations);
    }

    private function assertFailure(string $message): void
    {
        try {
            app(PrismShipmentProcessor::class)->process(1, true);
            $this->fail('Expected processor failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
