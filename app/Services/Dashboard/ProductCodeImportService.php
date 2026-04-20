<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

class ProductCodeImportService
{
    /**
     * @return array<int, string>
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $codes = match ($extension) {
            'csv', 'txt' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            default => throw ValidationException::withMessages([
                'products' => 'El archivo de productos debe ser .xlsx o .csv.',
            ]),
        };

        $codes = collect($codes)
            ->map(fn (string $code) => trim($code))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            throw ValidationException::withMessages([
                'products' => 'El archivo debe incluir al menos un codigo de producto en la columna A.',
            ]);
        }

        $joined = implode(',', $codes);

        if (strlen($joined) > 5000) {
            throw ValidationException::withMessages([
                'products' => 'La lista de codigos excede el limite permitido para la coleccion.',
            ]);
        }

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'products' => 'No fue posible leer el archivo de productos.',
            ]);
        }

        $codes = [];
        $row = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;

            if ($row === 1) {
                continue;
            }

            $codes[] = (string) ($data[0] ?? '');
        }

        fclose($handle);

        return $codes;
    }

    /**
     * @return array<int, string>
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

        $codes = [];

        foreach ($sheet->sheetData->row as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);

            if ($rowNumber <= 1) {
                continue;
            }

            foreach ($row->c as $cell) {
                $reference = strtoupper((string) ($cell['r'] ?? ''));

                if (! str_starts_with($reference, 'A')) {
                    continue;
                }

                $codes[] = $this->cellValue($cell, $sharedStrings, $styleFormats);
                break;
            }
        }

        return $codes;
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
     * Minimal ZIP reader for XLSX files. It avoids ext-zip, which is not enabled
     * in this local PHP runtime.
     *
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
        $offset = 0;
        $length = strlen($binary);

        while ($offset < $length - 30 && count($entries) < count($wanted)) {
            $signature = substr($binary, $offset, 4);

            if ($signature !== "PK\x03\x04") {
                $offset++;
                continue;
            }

            $header = unpack(
                'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength',
                substr($binary, $offset + 4, 26)
            );
            $nameStart = $offset + 30;
            $name = str_replace('\\', '/', substr($binary, $nameStart, $header['nameLength']));
            $dataStart = $nameStart + $header['nameLength'] + $header['extraLength'];
            $data = substr($binary, $dataStart, $header['compressed']);

            if (isset($wanted[$name])) {
                $entries[$name] = match ($header['method']) {
                    0 => $data,
                    8 => gzinflate($data),
                    default => throw ValidationException::withMessages([
                        'products' => 'El Excel usa un formato de compresion no soportado.',
                    ]),
                };
            }

            $offset = $dataStart + $header['compressed'];
        }

        return $entries;
    }
}
