<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Smtp2GoMailer
{
    /**
     * @param string|array<int|string, string>|null $cc
     * @param string|array<int|string, string>|null $bcc
     * @param array<int, array<string, mixed>> $attachments
     * @param array<int, array<string, mixed>> $inlines
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function sendHtml(
        string|array $to,
        string $subject,
        string $html,
        string|array|null $cc = null,
        string|array|null $bcc = null,
        array $attachments = [],
        array $inlines = [],
        ?string $sender = null,
    ): array {
        $payload = [
            'api_key' => $this->apiKey(),
            'sender' => $sender ?: $this->sender(),
            'subject' => $subject,
            'to' => $this->recipients($to),
            'cc' => $this->recipients($cc),
            'bcc' => $this->recipients($bcc),
            'html_body' => $html,
            'attachments' => $this->files($attachments),
            'inlines' => $this->files($inlines),
        ];

        if ($payload['to'] === []) {
            throw new RuntimeException('SMTP2GO requiere al menos un destinatario.');
        }

        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->asJson()
            ->post($this->url(), $payload);

        $response->throw();

        $result = $response->json() ?? [];
        $failed = (int) data_get($result, 'data.failed', 0);

        if ($failed > 0) {
            throw new RuntimeException('SMTP2GO reporto destinatarios fallidos: '.$this->failureMessage($result));
        }

        return $result;
    }

    /**
     * @return array{filename: string, fileblob: string, mimetype: string}
     */
    public function fileFromPath(string $path, ?string $filename = null, ?string $mimetype = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No existe el archivo para adjuntar: {$path}");
        }

        return [
            'filename' => $filename ?: basename($path),
            'fileblob' => base64_encode((string) file_get_contents($path)),
            'mimetype' => $mimetype ?: ((string) mime_content_type($path) ?: 'application/octet-stream'),
        ];
    }

    private function url(): string
    {
        $url = trim((string) config('services.smtp2go.url'));

        if ($url === '') {
            throw new RuntimeException('SMTP2GO_API_URL no esta configurado.');
        }

        return $url;
    }

    private function apiKey(): string
    {
        $key = trim((string) config('services.smtp2go.key'));

        if ($key === '') {
            throw new RuntimeException('SMTP2GO_API_KEY no esta configurado.');
        }

        return $key;
    }

    private function sender(): string
    {
        $sender = trim((string) config('services.smtp2go.sender'));

        if ($sender === '') {
            throw new RuntimeException('SMTP2GO_SENDER no esta configurado.');
        }

        return $sender;
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.smtp2go.timeout', 15));
    }

    /**
     * @param string|array<int|string, string>|null $recipients
     * @return array<int, string>
     */
    private function recipients(string|array|null $recipients): array
    {
        if ($recipients === null) {
            return [];
        }

        $recipients = is_array($recipients) ? $recipients : [$recipients];

        return collect($recipients)
            ->map(function (string $recipient, int|string $key): string {
                $recipient = trim($recipient);

                if (is_string($key) && ! is_numeric($key) && ! str_contains($recipient, '<')) {
                    return trim($recipient) !== ''
                        ? sprintf('"%s" <%s>', addslashes(trim($key)), $recipient)
                        : '';
                }

                return $recipient;
            })
            ->filter(fn (string $recipient) => $recipient !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function files(array $files): array
    {
        return collect($files)
            ->map(function (array $file): array {
                if (isset($file['path'])) {
                    return $this->fileFromPath(
                        (string) $file['path'],
                        isset($file['filename']) ? (string) $file['filename'] : null,
                        isset($file['mimetype']) ? (string) $file['mimetype'] : null,
                    );
                }

                return $file;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function failureMessage(array $result): string
    {
        $failures = data_get($result, 'data.failures', []);

        if (! is_array($failures) || $failures === []) {
            return 'sin detalle';
        }

        return json_encode($failures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'sin detalle';
    }
}
