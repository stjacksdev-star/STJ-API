<?php

namespace Tests\Unit;

use App\Services\Payments\PowerTranzClient;
use App\Services\Payments\PowerTranzConfigResolver;
use App\Services\Payments\PowerTranzPayloadFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PowerTranzConfigurationAndPayloadTest extends TestCase
{
    #[DataProvider('countries')]
    public function test_staging_configuration_resolves_each_country(string $country, string $currency): void
    {
        config(['powertranz.environment' => 'staging', 'powertranz.sale_url' => 'https://staging.ptranz.com/api/spi/sale', 'powertranz.payment_url' => 'https://staging.ptranz.com/api/spi/payment', "powertranz.credentials.{$country}" => ['id' => 'test-id', 'password' => 'test-password'], "powertranz.currencies.{$country}" => $currency]);
        $resolved = (new PowerTranzConfigResolver)->forCountry($country);
        $this->assertSame($currency, $resolved['currency']);
        $this->assertSame('staging.ptranz.com', $resolved['host']);
    }

    public function test_missing_credentials_and_invalid_environment_fail_controlled(): void
    {
        config(['powertranz.environment' => 'invalid']);
        $this->expectException(ValidationException::class);
        (new PowerTranzConfigResolver)->forCountry('sv');
    }

    public function test_honduras_payload_preserves_cents_and_inclusive_tax(): void
    {
        $order = (object) ['ped_pais' => 'HN', 'ped_nombres' => 'Test', 'ped_apellidos' => 'Customer', 'ped_email' => 'test@example.com'];
        $payment = (object) ['ppa_monto' => '115.01', 'ppa_ref' => 'SAFE-REFERENCE'];
        $payload = (new PowerTranzPayloadFactory)->sale($order, $payment, ['pan' => str_repeat('4', 16), 'cvv' => implode('', ['1', '2', '3']), 'expiration' => '3012', 'holder' => 'TEST CUSTOMER'], '340', '00000000-0000-4000-8000-000000000001', 'https://api.example.test/return');
        $this->assertSame('115.01', $payload['TotalAmount']);
        $this->assertSame('15.00', $payload['TaxAmount']);
        $this->assertSame('340', $payload['CurrencyCode']);
        $this->assertTrue($payload['ThreeDSecure']);
        $this->assertArrayHasKey('MerchantResponseUrl', $payload['ExtendedData']);
    }

    public function test_http_client_uses_required_headers_and_returns_3ds_data(): void
    {
        Http::fake(['https://staging.ptranz.com/*' => Http::response(['RedirectData' => '<form></form>'], 200)]);
        $configuration = ['sale_url' => 'https://staging.ptranz.com/api/spi/sale', 'payment_url' => 'https://staging.ptranz.com/api/spi/payment', 'id' => 'test-id', 'password' => 'test-password', 'connect_timeout' => 2, 'timeout' => 5];
        $result = (new PowerTranzClient)->sale($configuration, ['OrderIdentifier' => 'SAFE-REFERENCE'], 'correlation-id');
        $this->assertArrayHasKey('RedirectData', $result);
        Http::assertSent(fn ($request) => $request->hasHeader('PowerTranz-PowerTranzId') && $request->hasHeader('PowerTranz-PowerTranzPassword') && $request->hasHeader('X-Correlation-ID'));
    }

    public static function countries(): array
    {
        return [['sv', '840'], ['gt', '320'], ['cr', '188'], ['pa', '840'], ['hn', '340']];
    }
}
