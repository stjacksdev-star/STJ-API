<?php

namespace App\Services\Mail;

class StorefrontMailTemplate
{
    public function render(string $content, string $country): string
    {
        $country = strtolower(trim($country)) ?: 'sv';
        $homeUrl = e($this->storefrontUrl($country));
        $policiesUrl = e($this->storefrontUrl($country, 'politicas'));
        $termsUrl = e($this->storefrontUrl($country, 'terminos-y-condiciones'));
        $contactUrl = e($this->storefrontUrl($country, 'contactanos'));
        $year = now()->year;

        return <<<HTML
        <!doctype html>
        <html lang="es">
        <body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#0f172a">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8">
            <tr><td align="center" style="padding:24px 12px">
              <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-collapse:collapse">
                <tr><td><a href="{$homeUrl}" style="display:block"><img src="https://stj-assets.sfo3.cdn.digitaloceanspaces.com/img/correo/header-stjonline.png" alt="St. Jack's Online" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0"></a></td></tr>
                <tr><td style="padding:36px 38px 32px">{$content}</td></tr>
                <tr><td><a href="{$homeUrl}" style="display:block"><img src="https://stj-assets.sfo3.cdn.digitaloceanspaces.com/img/correo/footer-stjonline.png" alt="St. Jack's Online: fácil, rápido y seguro" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0"></a></td></tr>
                <tr><td align="center" style="padding:16px 24px 22px;border-top:1px solid #e5e7eb">
                  <p style="margin:0 0 14px;font-size:14px;color:#111827">Te esperamos nuevamente</p>
                  <p style="margin:0 0 10px;font-size:10px;line-height:1.5;color:#334155">TODOS LOS DERECHOS RESERVADOS.<br>{$year} © ST. JACK'S</p>
                  <p style="margin:0;font-size:11px;line-height:1.8"><a href="{$policiesUrl}" style="color:#0069b4;text-decoration:underline">Políticas</a><span style="color:#94a3b8"> &nbsp;•&nbsp; </span><a href="{$termsUrl}" style="color:#0069b4;text-decoration:underline">Términos y condiciones</a><span style="color:#94a3b8"> &nbsp;•&nbsp; </span><a href="{$contactUrl}" style="color:#0069b4;text-decoration:underline">Contáctanos</a></p>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    private function storefrontUrl(string $country, string $path = ''): string
    {
        $base = str_replace('{country}', $country, (string) config('services.storefront.web_url'));

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
