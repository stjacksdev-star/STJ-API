<?php

namespace Tests\Unit;

use App\Services\Dashboard\ProductMasterService;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductMasterDenimFitTest extends TestCase
{
    public function test_column_s_is_read_as_denim_fit(): void
    {
        $sheet = new Worksheet;
        $sheet->setCellValue('A2', '3600000001');
        $sheet->setCellValue('D2', 'ST JACKS');
        $sheet->setCellValue('S2', '  Wideleg  ');

        $data = $this->invoke('readProductRow', [$sheet, 2]);

        $this->assertSame('Wideleg', $data['denimFit']);
    }

    public function test_empty_denim_fit_is_persisted_as_null(): void
    {
        $data = array_fill_keys([
            'codigo', 'nombre', 'descripcion', 'marca', 'tags', 'tallas', 'personaje',
            'categoria', 'subcategoria', 'coleccion', 'oracleAnio', 'oracleTrimestre',
            'oracleColeccion', 'oracleGenero', 'oracleMarca', 'oracleCategoria',
            'oracleLicencia', 'oraclePersonaje', 'denimFit',
        ], '');
        $data['codigo'] = '3600000001';
        $data['nombre'] = 'Producto Denim';

        $payload = $this->invoke('productPayload', [$data, 5, 26]);

        $this->assertArrayHasKey('pro_denim_fit', $payload);
        $this->assertNull($payload['pro_denim_fit']);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(ProductMasterService::class, $method);

        return $reflection->invokeArgs(new ProductMasterService, $arguments);
    }
}
