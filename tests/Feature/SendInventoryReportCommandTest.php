<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendInventoryReportCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        $this->directory = storage_path('framework/testing/inventory-email-'.Str::uuid());
        File::ensureDirectoryExists($this->directory);
        $this->createSchema();
        $this->configureMail();
        $this->seedRun();
    }

    protected function tearDown(): void
    {
        if (str_starts_with($this->directory, storage_path('framework/testing/inventory-email-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_it_sends_valid_excels_with_comparison_and_records_delivery(): void
    {
        Http::fake(['https://smtp.test/send' => Http::response([
            'data' => ['succeeded' => 1, 'failed' => 0, 'email_id' => 'mail-123'],
        ])]);

        $this->artisan('inventory-report:send', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('Reporte 2026-09-23 enviado correctamente.')
            ->expectsOutputToContain('Adjuntos: 1')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $payload['to'] === ['qa@example.com']
                && $payload['cc'] === ['cc@example.com']
                && $payload['bcc'] === ['audit@example.com']
                && $payload['sender'] === '"Reporte Inventario" <no-reply@example.com>'
                && $payload['attachments'][0]['filename'] === 'ExistenciasECommerce - El Salvador.xlsx'
                && base64_decode($payload['attachments'][0]['fileblob'], true) === 'xlsx-content'
                && str_contains($payload['html_body'], 'Comparativa de productos por país')
                && str_contains($payload['html_body'], '<td class="number">2</td>')
                && str_contains($payload['html_body'], 'PARTIAL');
        });
        $this->assertDatabaseHas('stj_inventory_report_deliveries', [
            'ird_report_date' => '2026-09-23',
            'ird_attempt' => 1,
            'ird_status' => 'SENT',
            'ird_provider_reference' => 'mail-123',
        ]);
    }

    public function test_it_does_not_send_the_same_date_twice_without_force(): void
    {
        Http::fake(['https://smtp.test/send' => Http::response(['data' => ['succeeded' => 1, 'failed' => 0]])]);
        $this->artisan('inventory-report:send', ['--date' => '2026-09-23'])->assertSuccessful();
        $this->artisan('inventory-report:send', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('ya fue enviado en el intento 1')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('stj_inventory_report_deliveries')->count());
    }

    public function test_it_refuses_a_modified_attachment_before_creating_delivery(): void
    {
        $run = DB::table('stj_inventory_report_runs')->first();
        file_put_contents($run->irr_excel_path, 'modified');
        Http::fake();

        $this->artisan('inventory-report:send', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('no coincide con el hash registrado')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('stj_inventory_report_deliveries')->count());
    }

    public function test_mail_failure_is_audited_and_can_be_retried(): void
    {
        Http::fake(['https://smtp.test/send' => Http::sequence()
            ->push(['error' => 'temporary'], 503)
            ->push(['data' => ['succeeded' => 1, 'failed' => 0]], 200)]);

        $this->artisan('inventory-report:send', ['--date' => '2026-09-23'])->assertFailed();
        $this->assertDatabaseHas('stj_inventory_report_deliveries', ['ird_attempt' => 1, 'ird_status' => 'FAILED']);

        $this->artisan('inventory-report:send', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('Intento: 2')
            ->assertSuccessful();
        $this->assertDatabaseHas('stj_inventory_report_deliveries', ['ird_attempt' => 2, 'ird_status' => 'SENT']);
    }

    private function configureMail(): void
    {
        config()->set('cache.default', 'array');
        config()->set('services.smtp2go.url', 'https://smtp.test/send');
        config()->set('services.smtp2go.key', 'smtp-key');
        config()->set('services.smtp2go.sender', 'unused@example.com');
        config()->set('inventory_report.mail', [
            'from_address' => 'no-reply@example.com',
            'from_name' => 'Reporte Inventario',
            'to' => ['qa@example.com'],
            'cc' => ['cc@example.com'],
            'bcc' => ['audit@example.com'],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('stj_inventory_report_runs', function (Blueprint $table): void {
            $table->id('irr_id');
            $table->date('irr_report_date');
            $table->unsignedBigInteger('irr_country_id');
            $table->string('irr_country_code');
            $table->string('irr_country_name');
            $table->string('irr_status');
            $table->unsignedInteger('irr_expected_products');
            $table->unsignedInteger('irr_found_products');
            $table->unsignedInteger('irr_not_returned_products');
            $table->unsignedInteger('irr_failed_products');
            $table->unsignedBigInteger('irr_result_rows');
            $table->string('irr_excel_path');
            $table->string('irr_excel_sha256', 64);
        });
        Schema::create('stj_inventory_report_deliveries', function (Blueprint $table): void {
            $table->id('ird_id');
            $table->date('ird_report_date');
            $table->unsignedTinyInteger('ird_attempt');
            $table->string('ird_status');
            $table->text('ird_to');
            $table->text('ird_cc')->nullable();
            $table->text('ird_bcc')->nullable();
            $table->string('ird_subject');
            $table->text('ird_attachments')->nullable();
            $table->string('ird_provider_reference')->nullable();
            $table->text('ird_error')->nullable();
            $table->dateTime('ird_started_at')->nullable();
            $table->dateTime('ird_sent_at')->nullable();
            $table->dateTime('ird_created_at')->nullable();
            $table->unique(['ird_report_date', 'ird_attempt']);
        });
    }

    private function seedRun(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'ExistenciasECommerce - El Salvador.xlsx';
        file_put_contents($path, 'xlsx-content');
        DB::table('stj_inventory_report_runs')->insert([
            'irr_id' => 1,
            'irr_report_date' => '2026-09-23',
            'irr_country_id' => 1,
            'irr_country_code' => 'SV',
            'irr_country_name' => 'El Salvador',
            'irr_status' => 'PARTIAL',
            'irr_expected_products' => 10,
            'irr_found_products' => 8,
            'irr_not_returned_products' => 1,
            'irr_failed_products' => 1,
            'irr_result_rows' => 150,
            'irr_excel_path' => $path,
            'irr_excel_sha256' => hash_file('sha256', $path),
        ]);
    }
}
