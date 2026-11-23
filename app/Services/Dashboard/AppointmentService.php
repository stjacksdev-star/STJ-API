<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Exceptions\StjCitasIntegrationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AppointmentService
{
    public function __construct(
        private readonly StjCitasClient $stjCitas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(?string $country = null): array
    {
        $countryId = filled($country) ? $this->resolveCountryId((string) $country) : null;

        return [
            'countries' => $this->countries(),
            'stores' => $countryId ? $this->stores($countryId) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function appointments(array $filters): array
    {
        $countryId = $this->resolveCountryId((string) ($filters['country'] ?? ''));
        $allStores = strtoupper(trim((string) ($filters['store'] ?? ''))) === 'ALL';
        $store = $allStores ? null : $this->resolveStore($countryId, $filters['store'] ?? null);

        if (! $allStores && ! $store) {
            throw ValidationException::withMessages([
                'store' => 'Debe seleccionar una tienda.',
            ]);
        }

        $year = CarbonImmutable::now('America/El_Salvador')->year;
        $external = $this->stjCitas->appointments($countryId, $store['id'] ?? null, $year);
        $stores = collect($this->storeRecords($countryId))->keyBy('id');
        $rows = collect($external['rows'])
            ->map(function (array $row) use ($store, $stores, $countryId): array {
                $rowStore = $store ?? $stores->get((int) $row['storeId']);

                if (! $rowStore) {
                    throw new StjCitasIntegrationException(
                        'El servicio de citas devolvio una tienda no valida para el pais seleccionado.',
                        502,
                        'INTEGRATION_INVALID_RESPONSE',
                    );
                }

                return $this->normalizeAppointment($row, $rowStore, $countryId);
            })
            ->values()
            ->all();

        return [
            'countries' => $this->countries(),
            'stores' => $this->stores($countryId),
            'filters' => [
                'country' => $countryId,
                'store' => $store['code'] ?? null,
                'storeId' => $store['id'] ?? null,
                'storeName' => $store['name'] ?? 'Todas las tiendas',
            ],
            'summary' => [
                'appointments' => count($rows),
                'emailsSent' => count(array_filter($rows, fn (array $row) => (bool) $row['emailSent'])),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filename: string, contents: string}
     */
    public function export(array $filters): array
    {
        $report = $this->appointments($filters);
        $headers = [
            'Fecha registro', 'ID cita', 'Documento', 'Nombre', 'Telefono', 'Correo',
            'Tienda', 'Codigo tienda', 'Fecha cita', 'Horario', 'Correo enviado',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Citas');

        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($index + 1).'1',
                $header,
                DataType::TYPE_STRING,
            );
        }

        foreach ($report['rows'] as $rowIndex => $row) {
            $values = [
                $row['registeredAt'], $row['code'], $row['dui'], $row['name'], $row['phone'], $row['email'],
                $row['store']['name'], $row['store']['code'], $row['day'], $row['schedule'],
                $row['emailSent'] ? 'SI' : 'NO',
            ];

            foreach ($values as $columnIndex => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2),
                    (string) ($value ?? ''),
                    DataType::TYPE_STRING,
                );
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $lastRow = max(1, count($report['rows']) + 1);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A2');

        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        $country = (string) $report['filters']['country'];
        $store = $report['filters']['store'] ?: 'todas';
        $filename = "citas-{$country}-{$store}-".now('America/El_Salvador')->format('Ymd-His').'.xlsx';
        $path = tempnam(sys_get_temp_dir(), 'stj-citas-dashboard-');

        try {
            (new Xlsx($spreadsheet))->save($path);

            return ['filename' => $filename, 'contents' => (string) file_get_contents($path)];
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_nombre')
            ->get()
            ->map(fn (object $country) => [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
            ])
            ->values()
            ->all();
    }

    private function resolveCountryId(string $country): int
    {
        $country = trim($country);
        $query = DB::table('stj_paises')->select(['pai_id']);

        $resolved = is_numeric($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return (int) $resolved->pai_id;
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function resolveStore(int $countryId, mixed $store): ?array
    {
        $store = trim((string) $store);

        if ($store === '') {
            return null;
        }

        $query = DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId);

        $resolved = (clone $query)->where('tie_codigo', $store)->first();

        if (! $resolved && is_numeric($store)) {
            $resolved = (clone $query)->where('tie_id', (int) $store)->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages([
                'store' => 'La tienda seleccionada no existe para el pais indicado.',
            ]);
        }

        return [
            'id' => (int) $resolved->tie_id,
            'code' => (string) $resolved->tie_codigo,
            'name' => trim((string) $resolved->tie_nombre),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stores(int $countryId): array
    {
        return collect($this->storeRecords($countryId))
            ->map(fn (array $store) => [
                'storeId' => $store['id'],
                'storeCode' => $store['code'],
                'store' => $store['name'].' ('.$store['code'].')',
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, code: string, name: string}> */
    private function storeRecords(int $countryId): array
    {
        return DB::table('stj_tiendas')
            ->select(['tie_id', 'tie_codigo', 'tie_nombre'])
            ->where('tie_pais', $countryId)
            ->orderBy('tie_nombre')
            ->get()
            ->map(fn (object $store) => [
                'id' => (int) $store->tie_id,
                'code' => (string) $store->tie_codigo,
                'name' => trim((string) $store->tie_nombre),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAppointment(array $row, array $store, int $countryId): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'dui' => (string) $row['document'],
            'name' => trim((string) $row['name']),
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
            'registeredAt' => $row['registeredAt'],
            'schedule' => trim((string) $row['startTime']).' - '.trim((string) $row['endTime']),
            'day' => (string) $row['date'],
            'emailSent' => strtoupper((string) $row['emailStatus']) === 'ENVIADO',
            'store' => [
                'id' => $store['id'],
                'code' => $store['code'],
                'name' => $store['name'],
                'country' => $countryId,
            ],
        ];
    }
}
