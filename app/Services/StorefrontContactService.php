<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Str;
use RuntimeException;

class StorefrontContactService
{
    public function __construct(private readonly Smtp2GoMailer $mailer) {}

    /** @param array<string, string> $data */
    public function send(array $data, string $country): string
    {
        $recipient = trim((string) config('services.storefront.contact_to'));
        if ($recipient === '') throw new RuntimeException('No se ha configurado el destinatario del formulario de contacto.');

        $reference = 'WEB-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        $topics = [
            'order' => 'Pedido o compra',
            'product' => 'Producto o disponibilidad',
            'store' => 'Tienda o servicio',
            'website' => 'Sitio web',
            'other' => 'Otro',
        ];
        $topic = $topics[$data['topic']] ?? 'Otro';

        $this->mailer->sendHtml(
            $recipient,
            "Contacto web {$country} · {$topic} · {$reference}",
            $this->html($data, $country, $topic, $reference),
        );

        return $reference;
    }

    /** @param array<string, string> $data */
    private function html(array $data, string $country, string $topic, string $reference): string
    {
        $name = e($data['name']);
        $email = e($data['email']);
        $phone = e(trim($data['phone_country'].' '.$data['phone']));
        $message = nl2br(e($data['message']));
        $safeTopic = e($topic);
        $safeCountry = e($country);
        $safeReference = e($reference);

        return <<<HTML
        <div style="margin:0;background:#f4f6f8;padding:24px;font-family:Arial,sans-serif;color:#0f172a">
          <div style="max-width:620px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden">
            <img src="https://stj-assets.sfo3.cdn.digitaloceanspaces.com/img/correo/header-stjonline.png" alt="St. Jack's Online" width="620" style="display:block;width:100%;height:auto">
            <div style="padding:28px 32px">
              <p style="margin:0 0 8px;color:#0284c7;font-size:12px;font-weight:bold;letter-spacing:1px">FORMULARIO DE CONTACTO · {$safeCountry}</p>
              <h1 style="margin:0 0 22px;font-size:24px">Nueva consulta {$safeReference}</h1>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="font-size:14px;border-collapse:collapse">
                <tr><td style="width:110px;color:#64748b">Asunto</td><td><strong>{$safeTopic}</strong></td></tr>
                <tr><td style="color:#64748b">Nombre</td><td>{$name}</td></tr>
                <tr><td style="color:#64748b">Correo</td><td><a href="mailto:{$email}" style="color:#0369a1">{$email}</a></td></tr>
                <tr><td style="color:#64748b">Teléfono</td><td>{$phone}</td></tr>
              </table>
              <div style="margin-top:22px;padding:18px;background:#f8fafc;border-radius:12px;font-size:14px;line-height:1.65">{$message}</div>
              <p style="margin:22px 0 0;color:#94a3b8;font-size:11px">Enviado desde stjecommerce · {$safeReference}</p>
            </div>
          </div>
        </div>
        HTML;
    }
}
