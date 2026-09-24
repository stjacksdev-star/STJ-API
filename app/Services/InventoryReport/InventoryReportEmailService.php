<?php

namespace App\Services\InventoryReport;

use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class InventoryReportEmailService
{
    public function __construct(private readonly Smtp2GoMailer $mailer) {}

    /** @return array<string, mixed> */
    public function send(string $reportDate, bool $force = false): array
    {
        $runs = DB::table('stj_inventory_report_runs')
            ->whereDate('irr_report_date', $reportDate)
            ->orderBy('irr_country_id')
            ->get();
        if ($runs->isEmpty()) {
            throw new RuntimeException("No existen corridas para {$reportDate}.");
        }

        $open = $runs->reject(fn (object $run): bool => in_array((string) $run->irr_status, ['COMPLETE', 'PARTIAL'], true));
        if ($open->isNotEmpty()) {
            $countries = $open->map(fn (object $run): string => "{$run->irr_country_code} ({$run->irr_status})")->implode(', ');
            throw new RuntimeException("No se puede enviar el reporte; hay corridas abiertas: {$countries}.");
        }

        $attachments = [];
        $attachmentLog = [];
        foreach ($runs as $run) {
            $path = trim((string) $run->irr_excel_path);
            $expectedHash = trim((string) $run->irr_excel_sha256);
            if ($path === '' || ! is_file($path)) {
                throw new RuntimeException("No existe el Excel vigente de {$run->irr_country_code}.");
            }
            $actualHash = hash_file('sha256', $path);
            if ($expectedHash === '' || $actualHash === false || ! hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException("El Excel de {$run->irr_country_code} no coincide con el hash registrado.");
            }
            $attachments[] = [
                'path' => $path,
                'filename' => basename($path),
                'mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            $attachmentLog[] = ['filename' => basename($path), 'sha256' => $actualHash];
        }

        $to = (array) config('inventory_report.mail.to', []);
        $cc = (array) config('inventory_report.mail.cc', []);
        $bcc = (array) config('inventory_report.mail.bcc', []);
        if ($to === []) {
            throw new RuntimeException('INVENTORY_REPORT_MAIL_TO no contiene destinatarios validos.');
        }

        $existing = DB::table('stj_inventory_report_deliveries')
            ->whereDate('ird_report_date', $reportDate)
            ->orderByDesc('ird_attempt')
            ->first();
        if (! $force && $existing !== null && $existing->ird_status === 'SENT') {
            return [
                'sent' => false,
                'alreadySent' => true,
                'deliveryId' => (int) $existing->ird_id,
                'attempt' => (int) $existing->ird_attempt,
                'date' => $reportDate,
                'attachments' => count($attachments),
            ];
        }
        if (! $force && $existing !== null && $existing->ird_status === 'PENDING') {
            throw new RuntimeException("El envio {$existing->ird_id} permanece PENDING; revise su resultado antes de usar --force.");
        }

        $attempt = (int) ($existing->ird_attempt ?? 0) + 1;
        $subject = "Existencias | St. Jack's Online";
        $deliveryId = DB::table('stj_inventory_report_deliveries')->insertGetId([
            'ird_report_date' => $reportDate,
            'ird_attempt' => $attempt,
            'ird_status' => 'PENDING',
            'ird_to' => $this->json($to),
            'ird_cc' => $this->json($cc),
            'ird_bcc' => $this->json($bcc),
            'ird_subject' => $subject,
            'ird_attachments' => $this->json($attachmentLog),
            'ird_started_at' => now(),
            'ird_created_at' => now(),
        ]);

        try {
            $result = $this->mailer->sendHtml(
                to: $to,
                subject: $subject,
                html: $this->html($runs->all(), $reportDate),
                cc: $cc,
                bcc: $bcc,
                attachments: $attachments,
                sender: $this->sender(),
            );
            DB::table('stj_inventory_report_deliveries')->where('ird_id', $deliveryId)->update([
                'ird_status' => 'SENT',
                'ird_provider_reference' => mb_substr($this->providerReference($result), 0, 255),
                'ird_error' => null,
                'ird_sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            DB::table('stj_inventory_report_deliveries')->where('ird_id', $deliveryId)->update([
                'ird_status' => 'FAILED',
                'ird_error' => mb_substr($exception->getMessage(), 0, 65000),
            ]);

            throw $exception;
        }

        return [
            'sent' => true,
            'alreadySent' => false,
            'deliveryId' => $deliveryId,
            'attempt' => $attempt,
            'date' => $reportDate,
            'attachments' => count($attachments),
        ];
    }

    /** @param array<int, object> $runs */
    private function html(array $runs, string $reportDate): string
    {
        $details = '';
        $rows = '';
        foreach ($runs as $run) {
            $expected = (int) $run->irr_expected_products;
            $found = (int) $run->irr_found_products;
            $difference = max(0, $expected - $found);
            $coverage = $expected > 0 ? round($found * 100 / $expected, 2) : 100.0;
            $details .= $this->e($run->irr_country_name).' registros: '.number_format((int) $run->irr_result_rows).'<br/>';
            $statusColor = $run->irr_status === 'COMPLETE' ? '#137333' : '#b06000';
            $rows .= '<tr>'
                .'<td>'.$this->e($run->irr_country_name).'</td>'
                .'<td class="number">'.$expected.'</td>'
                .'<td class="number">'.$found.'</td>'
                .'<td class="number">'.$difference.'</td>'
                .'<td class="number">'.(int) $run->irr_not_returned_products.'</td>'
                .'<td class="number">'.(int) $run->irr_failed_products.'</td>'
                .'<td class="number">'.number_format($coverage, 2).'%</td>'
                .'<td style="color:'.$statusColor.';font-weight:bold">'.$this->e($run->irr_status).'</td>'
                .'</tr>';
        }

        return '<style>body{font-family:Arial,sans-serif;color:#222}table{border-collapse:collapse;min-width:760px;margin-top:8px}th,td{border:1px solid #b8c9ce;padding:7px;text-align:left}th{background:#ddffff}.number{text-align:right}.meta{font-size:12px;color:#555}</style>'
            .'<table style="width:100%;min-width:0"><tr><td style="border-left:6px solid rgb(0,122,201);background:#ddffff;padding:10px"><h2 style="margin:0">Reporte diario de existencias e-commerce</h2></td></tr></table>'
            .'<p>Fecha del reporte: <strong>'.$this->e($reportDate).'</strong></p>'
            .'<p>'.$details.'</p>'
            .'<h3>Comparativa de productos por país</h3>'
            .'<table><thead><tr><th>País</th><th>Productos activos</th><th>Estilos encontrados</th><th>Diferencia</th><th>No devueltos</th><th>Fallidos</th><th>Cobertura</th><th>Estado</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p class="meta">Los productos no devueltos agotaron los intentos configurados. Las API actuales no confirman explícitamente que el código sea inexistente.</p>'
            .'<p class="meta">Proceso automático | Enviado el '.now((string) config('inventory_report.timezone'))->format('d/m/Y h:i:s a').'</p>';
    }

    private function sender(): string
    {
        $address = trim((string) config('inventory_report.mail.from_address'));
        $name = trim((string) config('inventory_report.mail.from_name'));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('INVENTORY_REPORT_MAIL_FROM_ADDRESS no contiene un correo valido.');
        }

        return $name === '' ? $address : sprintf('"%s" <%s>', addslashes($name), $address);
    }

    /** @param array<string, mixed> $result */
    private function providerReference(array $result): string
    {
        return (string) (data_get($result, 'data.email_id')
            ?? data_get($result, 'data.request_id')
            ?? data_get($result, 'request_id')
            ?? '');
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
