<?php

namespace Tests\Unit;

use App\Services\InventoryReport\Adapters\ElSalvadorInventoryReportAdapter;
use App\Services\InventoryReport\Adapters\HondurasInventoryReportAdapter;
use App\Services\InventoryReport\Adapters\RegionalInventoryReportAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryReportAdapterTest extends TestCase
{
    public function test_el_salvador_contract_builds_legacy_payload_and_normalizes_numeric_values(): void
    {
        $adapter = new ElSalvadorInventoryReportAdapter;

        $this->assertSame([
            'Pais' => '1',
            'Codigos' => "'A-1','B-2'",
            'Tiendas' => "'019','57'",
        ], $adapter->payload(1, 'SV', ['A-1', 'B-2'], ['019', '57']));

        $response = $adapter->normalize(['datos' => [[
            'estilo' => 'A-1', 'tienda' => '019', 'talla' => 'M', 'existencia' => '12', 'PRECIO' => '19.95',
        ]]], 'SV', ['A-1', 'B-2']);

        $this->assertSame(12, $response->rows[0]['quantity']);
        $this->assertSame(19.95, $response->rows[0]['sale_price']);
        $this->assertSame(['A-1'], $response->returnedCodes);
        $this->assertSame(['B-2'], $response->notReturnedCodes);
    }

    #[DataProvider('regionalCountries')]
    public function test_regional_contract_uses_the_country_specific_request_and_response_keys(
        string $country,
        int $countryId,
        string $requestKey,
        string $responseKey,
    ): void {
        $adapter = new RegionalInventoryReportAdapter;
        $payload = $adapter->payload($countryId, $country, ['P001'], []);

        $this->assertSame("'P001'", $payload[$requestKey]);
        $this->assertSame('', $payload['Estilos']);

        $response = $adapter->normalize([$responseKey => [[
            'estilo' => 'P001', 'tienda' => '1', 'talla' => 'S', 'existencia' => 3, 'PRECIO' => 10,
        ]]], $country, ['P001']);

        $this->assertSame(['P001'], $response->returnedCodes);
        $this->assertSame([], $response->notReturnedCodes);
    }

    public static function regionalCountries(): array
    {
        return [
            'Guatemala' => ['GT', 2, 'EstilosGT', 'gt_datos'],
            'Costa Rica' => ['CR', 3, 'EstilosCR', 'cr_datos'],
            'Panama' => ['PA', 5, 'EstilosPA', 'pa_datos'],
        ];
    }

    public function test_honduras_contract_preserves_its_distinct_store_format(): void
    {
        $adapter = new HondurasInventoryReportAdapter;

        $this->assertSame([
            'Pais' => '7',
            'Tiendas' => "'005,002,001'",
            'Codigos' => "'HN-1'",
        ], $adapter->payload(7, 'HN', ['HN-1'], ['005', '002', '001']));
    }

    public function test_invalid_rows_are_reported_without_marking_the_product_as_returned(): void
    {
        $adapter = new ElSalvadorInventoryReportAdapter;
        $response = $adapter->normalize(['datos' => [[
            'estilo' => 'A-1', 'tienda' => '019', 'talla' => 'M', 'existencia' => 'no-numerica',
        ]]], 'SV', ['A-1']);

        $this->assertSame([], $response->rows);
        $this->assertSame(['A-1'], $response->notReturnedCodes);
        $this->assertNotEmpty($response->warnings);
    }

    public function test_missing_response_collection_is_a_contract_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HondurasInventoryReportAdapter)->normalize([], 'HN', ['HN-1']);
    }
}
