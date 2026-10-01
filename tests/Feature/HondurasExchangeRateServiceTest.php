<?php

namespace Tests\Feature;

use App\Services\HondurasExchangeRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class HondurasExchangeRateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tasa_hnl_usd', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('tasa', 12, 8);
        });
        config()->set('hn_exchange_rate.url', 'https://rates.test/v6/latest/HNL');
        config()->set('hn_exchange_rate.enabled', true);
    }

    public function test_it_inserts_and_updates_the_daily_hnl_to_usd_rate(): void
    {
        Http::fakeSequence()
            ->push(['result' => 'success', 'rates' => ['USD' => 0.04012345]])
            ->push(['result' => 'success', 'rates' => ['USD' => 0.04054321]]);
        $date = Carbon::parse('2026-10-02', 'America/Tegucigalpa');

        $created = app(HondurasExchangeRateService::class)->sync($date);
        $updated = app(HondurasExchangeRateService::class)->sync($date);

        $this->assertTrue($created['created']);
        $this->assertFalse($updated['created']);
        $this->assertDatabaseCount('tasa_hnl_usd', 1);
        $this->assertSame(0.04054321, (float) DB::table('tasa_hnl_usd')->value('tasa'));
        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_response_without_a_valid_usd_rate(): void
    {
        Http::fake(['*' => Http::response(['result' => 'error', 'rates' => []])]);

        $this->expectException(RuntimeException::class);
        app(HondurasExchangeRateService::class)->sync(Carbon::parse('2026-10-02'));
    }

    public function test_console_command_succeeds_and_writes_the_rate(): void
    {
        Http::fake(['*' => Http::response(['result' => 'success', 'rates' => ['USD' => 0.04]])]);
        Carbon::setTestNow(Carbon::parse('2026-10-02 07:00:00', 'America/Tegucigalpa'));

        try {
            $this->artisan('exchange-rate:sync-hnl-usd')->assertSuccessful();
            $this->assertDatabaseHas('tasa_hnl_usd', ['fecha' => '2026-10-02', 'tasa' => 0.04]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
