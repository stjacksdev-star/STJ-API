<?php

namespace Tests\Unit;

use App\Services\Payments\PowerTranzClient;
use App\Services\Payments\PowerTranzConfigResolver;
use App\Services\Payments\PowerTranzPayloadFactory;
use App\Services\Payments\PowerTranzUrlFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

    #[DataProvider('countries')]
    public function test_payload_matches_historical_contract(string $country, string $currency): void
    {
        $order = (object) ['ped_pais' => strtoupper($country), 'ped_nombres' => 'José', 'ped_apellidos' => 'Muñoz', 'ped_email' => 'test@example.com', 'ped_telefono_pais' => '+503', 'ped_telefono' => '7000-1234', 'ped_direccion' => 'No enviar', 'ped_ciudad' => 'No enviar'];
        $payment = (object) ['ppa_monto' => '115.01', 'ppa_ref' => 'SAFE-REFERENCE'];
        $payload = (new PowerTranzPayloadFactory)->sale($order, $payment, ['pan' => str_repeat('4', 16), 'cvv' => implode('', ['1', '2', '3']), 'expiration' => '3012', 'holder' => 'TEST CUSTOMER'], $currency, '00000000-0000-4000-8000-000000000001', "https://api.example.test/return/{$country}/opaque");
        $expectedKeys = ['TransactionIdentifier', 'TotalAmount', 'CurrencyCode', 'ThreeDSecure', 'Source', 'OrderIdentifier', 'BillingAddress', 'AddressMatch', 'ExtendedData'];
        if ($country === 'hn') $expectedKeys[] = 'TaxAmount';
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($payload));
        $this->assertSame(['CardPresent', 'CardEmvFallback', 'ManualEntry', 'Debit', 'Contactless', 'CardPan', 'CardCvv', 'CardExpiration', 'CardholderName'], array_keys($payload['Source']));
        $this->assertSame(['FirstName', 'LastName', 'Line1', 'Line2', 'City', 'State', 'PostalCode', 'CountryCode', 'EmailAddress', 'PhoneNumber'], array_keys($payload['BillingAddress']));
        $this->assertSame(['ThreeDSecure', 'MerchantResponseUrl'], array_keys($payload['ExtendedData']));
        $this->assertSame(['ChallengeWindowSize'], array_keys($payload['ExtendedData']['ThreeDSecure']));
        $this->assertSame(115.01, $payload['TotalAmount']);
        $this->assertIsFloat($payload['TotalAmount']);
        $this->assertSame($currency, $payload['CurrencyCode']);
        $this->assertSame('', $payload['BillingAddress']['Line1']);
        $this->assertSame('', $payload['BillingAddress']['City']);
        $this->assertSame('', $payload['BillingAddress']['CountryCode']);
        $this->assertSame('test@example.com', $payload['BillingAddress']['EmailAddress']);
        $this->assertSame('50370001234', $payload['BillingAddress']['PhoneNumber']);
        $this->assertArrayNotHasKey('ShippingAmount', $payload);
        $country === 'hn' ? $this->assertEquals(15.0, $payload['TaxAmount']) : $this->assertArrayNotHasKey('TaxAmount', $payload);
        $this->assertSame("https://api.example.test/return/{$country}/opaque", $payload['ExtendedData']['MerchantResponseUrl']);
    }

    public function test_http_client_uses_required_headers_and_returns_3ds_data(): void
    {
        Http::fake(['https://staging.ptranz.com/*' => Http::response(['RedirectData' => '<form></form>'], 200)]);
        $configuration = ['sale_url' => 'https://staging.ptranz.com/api/spi/sale', 'payment_url' => 'https://staging.ptranz.com/api/spi/payment', 'id' => 'test-id', 'password' => 'test-password', 'connect_timeout' => 2, 'timeout' => 5];
        $result = (new PowerTranzClient)->sale($configuration, ['TotalAmount' => 1.0, 'OrderIdentifier' => 'SAFE-REFERENCE'], 'correlation-id');
        $this->assertArrayHasKey('RedirectData', $result);
        Http::assertSent(fn ($request) => $request->hasHeader('PowerTranz-PowerTranzId') && $request->hasHeader('PowerTranz-PowerTranzPassword') && $request->hasHeader('X-Correlation-ID'));
    }

    public function test_sale_serializes_historical_numeric_total_format(): void
    {
        Http::fake(['https://staging.ptranz.com/*' => Http::response(['RedirectData' => '<form></form>'], 200)]);
        $configuration = ['sale_url' => 'https://staging.ptranz.com/api/spi/sale', 'payment_url' => 'https://staging.ptranz.com/api/spi/payment', 'id' => 'test-id', 'password' => 'test-password', 'connect_timeout' => 2, 'timeout' => 5];
        $client = new PowerTranzClient;
        $client->sale($configuration, ['TotalAmount' => 115.0, 'CurrencyCode' => '840'], 'sv-operation');
        Http::assertSent(fn ($request) => $request->body() === '{"TotalAmount":115.00,"CurrencyCode":"840"}');
        Http::fake(['https://staging.ptranz.com/*' => Http::response(['RedirectData' => '<form></form>'], 200)]);
        $client->sale($configuration, ['TotalAmount' => 115.0, 'TaxAmount' => 15.0, 'CurrencyCode' => '340'], 'hn-operation');
        Http::assertSent(fn ($request) => $request->body() === '{"TotalAmount":115,"TaxAmount":15,"CurrencyCode":"340"}');
    }

    public function test_phone_country_code_is_not_duplicated(): void
    {
        $factory = new PowerTranzPayloadFactory;
        $order = (object) ['ped_pais' => 'SV', 'ped_nombres' => 'Ana', 'ped_apellidos' => 'Lopez', 'ped_email' => 'ana@example.test', 'ped_telefono_pais' => '503', 'ped_telefono' => '+503 7704-2525'];
        $payment = (object) ['ppa_monto' => '10.45', 'ppa_ref' => 'STJ-TEST'];
        $payload = $factory->sale($order, $payment, ['pan' => '4012000000020006', 'cvv' => '123', 'expiration' => '2812', 'holder' => 'Ana Lopez'], '840', 'operation-id', 'https://api.example.test/return');

        $this->assertSame('50377042525', $payload['BillingAddress']['PhoneNumber']);
    }

    public function test_expiration_from_checkout_is_converted_from_month_year_to_powertranz_year_month(): void
    {
        $order = (object) ['ped_pais' => 'SV', 'ped_nombres' => 'Ana', 'ped_apellidos' => 'Lopez', 'ped_email' => 'ana@example.test', 'ped_telefono_pais' => '503', 'ped_telefono' => '7000-0000'];
        $payment = (object) ['ppa_monto' => '10.00', 'ppa_ref' => 'STJ-EXPIRATION'];
        $payload = (new PowerTranzPayloadFactory)->sale($order, $payment, [
            'pan' => '4012000000020006', 'cvv' => '123', 'expiration' => '12/30', 'holder' => 'Ana Lopez',
        ], '840', 'operation-id', 'https://api.example.test/return');

        $this->assertSame('3012', $payload['Source']['CardExpiration']);
    }

    public function test_sv_public_payload_preflight_uses_persisted_authority_and_opaque_return(): void
    {
        config(['powertranz.return_base_url' => 'https://test-api.stjacks.com/api/storefront/payments/powertranz/return']);
        $token = str_repeat('A', 64);
        $returnUrl = (new PowerTranzUrlFactory)->returnUrl('sv', $token);
        $order = (object) ['ped_pais' => 'SV', 'ped_nombres' => 'Ana', 'ped_apellidos' => 'Lopez', 'ped_email' => 'ana@example.test', 'ped_telefono_pais' => '+503', 'ped_telefono' => '7000-0000'];
        $payment = (object) ['ppa_monto' => '42.50', 'ppa_ref' => 'STAGING-SAFE-REFERENCE'];
        $payload = (new PowerTranzPayloadFactory)->sale($order, $payment, ['pan' => str_repeat('4', 16), 'cvv' => implode('', ['1', '2', '3']), 'expiration' => '3012', 'holder' => 'ANA LOPEZ'], '840', '00000000-0000-4000-8000-000000000001', $returnUrl);

        $this->assertSame(['TransactionIdentifier', 'TotalAmount', 'CurrencyCode', 'ThreeDSecure', 'Source', 'OrderIdentifier', 'BillingAddress', 'AddressMatch', 'ExtendedData'], array_keys($payload));
        $this->assertSame(42.5, $payload['TotalAmount']);
        $this->assertIsFloat($payload['TotalAmount']);
        $this->assertSame('840', $payload['CurrencyCode']);
        $this->assertSame($payment->ppa_ref, $payload['OrderIdentifier']);
        $this->assertSame($order->ped_email, $payload['BillingAddress']['EmailAddress']);
        $this->assertSame('50370000000', $payload['BillingAddress']['PhoneNumber']);
        $this->assertArrayNotHasKey('TaxAmount', $payload);
        $this->assertArrayNotHasKey('ShippingAmount', $payload);
        $this->assertSame('https', parse_url($returnUrl, PHP_URL_SCHEME));
        $this->assertNull(parse_url($returnUrl, PHP_URL_QUERY));
        $this->assertSame($token, basename($returnUrl));
    }

    public function test_confirmation_sends_spi_token_as_json_string(): void
    {
        Http::fake(['https://staging.ptranz.com/*' => Http::response(['Approved' => true], 200)]);
        $configuration = ['sale_url' => 'https://staging.ptranz.com/api/spi/sale', 'payment_url' => 'https://staging.ptranz.com/api/spi/payment', 'id' => 'test-id', 'password' => 'test-password', 'connect_timeout' => 2, 'timeout' => 5];
        (new PowerTranzClient)->confirm($configuration, 'opaque-spi-token', 'correlation-id');
        Http::assertSent(fn ($request) => $request->url() === $configuration['payment_url'] && $request->body() === json_encode('opaque-spi-token'));
    }

    public function test_powertranz_return_route_accepts_post_only(): void
    {
        $route = Route::getRoutes()->getByName('powertranz.return');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
    }

    #[DataProvider('urlEnvironments')]
    public function test_return_and_frontend_urls_are_generated_from_configuration(string $returnBase, string $frontendTemplate): void
    {
        config(['powertranz.return_base_url' => $returnBase, 'powertranz.frontend_result_url' => $frontendTemplate]);
        $urls = new PowerTranzUrlFactory;
        $this->assertSame("{$returnBase}/sv/opaque-token", $urls->returnUrl('SV', 'opaque-token'));
        $this->assertSame(str_replace(['{country}', '{hint}'], ['sv', 'aprobada'], $frontendTemplate), $urls->frontendResultUrl('SV', 'APROBADA'));
    }

    public static function countries(): array
    {
        return [['sv', '840'], ['gt', '320'], ['cr', '188'], ['pa', '840'], ['hn', '340']];
    }

    public static function urlEnvironments(): array
    {
        return [
            'local mocks' => ['http://localhost/stj-api/public/api/storefront/payments/powertranz/return', 'http://localhost/stj-ecommerce/public/{country}/pago/resultado/{hint}'],
            'public staging' => ['https://test-api.stjacks.com/api/storefront/payments/powertranz/return', 'https://stjecommerce.stjacks.com/{country}/pago/resultado/{hint}'],
        ];
    }
}
