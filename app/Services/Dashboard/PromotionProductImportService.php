<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

class PromotionProductImportService
{
    /**
     * @return array<int, array{code: string, discount: ?float, price: ?float}>
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $rows = match ($extension) {
            'csv', 'txt' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            default => throw ValidationException::withMessages([
                'products' => 'El archivo de productos debe ser .xlsx o .csv.',
            ]),
        };

        $rows = collect($rows)
            ->map(fn (array $row) => [
                'code' => $this->normalizeCode($row['code'] ?? ''),
                'discount' => $this->numberOrNull($row['discount'] ?? null),
                'price' => $this->numberOrNull($row['price'] ?? null),
            ])
            ->filter(fn (array $row) => $row['code'] !== '')
            ->unique('code')
            ->values()
            ->all();

        if ($rows === []) {
            throw ValidationException::withMessages([
                'products' => 'El archivo debe incluir al menos un codigo de producto en la columna A.',
            ]);
        }

        return $rows;
    }

    /**
     * @return array<int, array{code: string, discount: mixed, price: mixed}>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'products' => 'No fue posible leer el archivo de productos.',
            ]);
        }

        $rows = [];
        $row = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;

            if ($row === 1) {
                continue;
            }

            $rows[] = [
                'code' => $data[0] ?? '',
                'discount' => $data[1] ?? null,
                'price' => $data[2] ?? null,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array{code: string, discount: mixed, price: mixed}>
     */
    private function readXlsx(string $path): array
    {
        $entries = $this->readZipEntries($path, [
            'xl/sharedStrings.xml',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ]);

        if (! isset($entries['xl/worksheets/sheet1.xml'])) {
            throw ValidationException::withMessages([
                'products' => 'No fue posible encontrar la primera hoja del Excel.',
            ]);
        }

        $sharedStrings = isset($entries['xl/sharedStrings.xml'])
            ? $this->sharedStrings($entries['xl/sharedStrings.xml'])
            : [];
        $styleFormats = isset($entries['xl/styles.xml'])
            ? $this->styleFormats($entries['xl/styles.xml'])
            : [];
        $sheet = simplexml_load_string($entries['xl/worksheets/sheet1.xml']);

        if (! $sheet instanceof SimpleXMLElement) {
            throw ValidationException::withMessages([
                'products' => 'No fue posible leer la hoja de productos.',
            ]);
        }

        $rows = [];

        foreach ($sheet->sheetData->row as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);

            if ($rowNumber <= 1) {
                continue;
            }

            $values = ['A' => '', 'B' => null, 'C' => null];

            foreach ($row->c as $cell) {
                $reference = strtoupper((string) ($cell['r'] ?? ''));
                $column = preg_replace('/\d+/', '', $reference);

                if (! in_array($column, ['A', 'B', 'C'], true)) {
                    continue;
                }

                $values[$column] = $this->cellValue($cell, $sharedStrings, $styleFormats);
            }

            $rows[] = [
                'code' => $values['A'],
                'discount' => $values['B'],
                'price' => $values['C'],
            ];
        }

        return $rows;
    }

    private function normalizeCode(mixed $code): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return '';
        }

        if (preg_match('/^\d+(\.0+)?$/', $code)) {
            $code = (string) (int) $code;
        }

        if (preg_match('/^\d+$/', $code) && strlen($code) < 10) {
            return str_pad($code, 10, '0', STR_PAD_LEFT);
        }

        return $code;
    }

    private function numberOrNull(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @param array<int, string> $styleFormats
     */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings, array $styleFormats): string
    {
        $type = (string) ($cell['t'] ?? '');
        $style = (int) ($cell['s'] ?? -1);
        $value = isset($cell->v) ? (string) $cell->v : '';

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        if ($value !== '' && isset($styleFormats[$style]) && preg_match('/^0+$/', $styleFormats[$style])) {
            return str_pad($value, strlen($styleFormats[$style]), '0', STR_PAD_LEFT);
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(string $xml): array
    {
        $strings = simplexml_load_string($xml);

        if (! $strings instanceof SimpleXMLElement) {
            return [];
        }

        $values = [];

        foreach ($strings->si as $item) {
            if (isset($item->t)) {
                $values[] = (string) $item->t;
                continue;
            }

            $text = '';

            foreach ($item->r as $run) {
                $text .= (string) ($run->t ?? '');
            }

            $values[] = $text;
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function styleFormats(string $xml): array
    {
        $styles = simplexml_load_string($xml);

        if (! $styles instanceof SimpleXMLElement) {
            return [];
        }

        $numberFormats = [];

        foreach ($styles->numFmts->numFmt ?? [] as $format) {
            $numberFormats[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }

        $styleFormats = [];
        $index = 0;

        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $formatId = (int) $xf['numFmtId'];
            $styleFormats[$index] = $numberFormats[$formatId] ?? '';
            $index++;
        }

        return $styleFormats;
    }

    /**
     * @param array<int, string> $wanted
     * @return array<string, string>
     */
    private function readZipEntries(string $path, array $wanted): array
    {
        $binary = file_get_contents($path);

        if ($binary === false) {
            throw ValidationException::withMessages([
                'products' => 'No fue posible leer el archivo Excel.',
            ]);
        }

        $wanted = array_flip($wanted);
        $entries = [];
        $centralDirectoryOffset = $this->centralDirectoryOffset($binary);
        $offset = $centralDirectoryOffset;
        $length = strlen($binary);

        while ($offset < $length - 46 && count($entries) < count($wanted)) {
            $signature = substr($binary, $offset, 4);

            if ($signature !== "PK\x01\x02") {
                break;
            }

            $header = unpack(
                'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/VlocalOffset',
                substr($binary, $offset + 4, 42)
            );
            $nameStart = $offset + 46;
            $name = str_replace('\\', '/', substr($binary, $nameStart, $header['nameLength']));

            if (isset($wanted[$name])) {
                $localOffset = $header['localOffset'];
                $local = unpack(
                    'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength',
                    substr($binary, $localOffset + 4, 26)
                );
                $dataStart = $localOffset + 30 + $local['nameLength'] + $local['extraLength'];
                $data = substr($binary, $dataStart, $header['compressed']);

                $entries[$name] = match ($header['method']) {
                    0 => $data,
                    8 => $this->inflateZipEntry($data),
                    default => throw ValidationException::withMessages([
                        'products' => 'El Excel usa un formato de compresion no soportado.',
                    ]),
                };
            }

            $offset = $nameStart + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
        }

        return $entries;
    }

    private function centralDirectoryOffset(string $binary): int
    {
        $eocdOffset = strrpos($binary, "PK\x05\x06");

        if ($eocdOffset === false) {
            throw ValidationException::withMessages([
                'products' => 'El archivo Excel no tiene una estructura ZIP valida.',
            ]);
        }

        $directory = unpack('Vsize/Voffset', substr($binary, $eocdOffset + 12, 8));

        return (int) $directory['offset'];
    }

    private function inflateZipEntry(string $data): string
    {
        $inflated = @gzinflate($data);

        if ($inflated === false) {
            throw ValidationException::withMessages([
                'products' => 'No fue posible descomprimir el contenido del Excel.',
            ]);
        }

        return $inflated;
    }
}
