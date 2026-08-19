<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StorefrontPasswordResetService
{
    public function __construct(private readonly Smtp2GoMailer $mailer) {}

    public function request(string $email, string $country, ?string $ip): void
    {
        $customer = StorefrontCustomer::query()
            ->whereRaw('LOWER(usu_usuario) = ?', [$email])
            ->where('usu_activo', 1)
            ->first();

        if (! $customer) return;

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        DB::transaction(function () use ($email, $tokenHash, $ip) {
            DB::table('stj_storefront_password_resets')->where('spr_email', $email)->delete();
            DB::table('stj_storefront_password_resets')->insert([
                'spr_email' => $email,
                'spr_token_hash' => $tokenHash,
                'spr_expires_at' => now()->addMinutes($this->ttlMinutes()),
                'spr_request_ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->mailer->sendHtml(
                $email,
                'Restablece tu contraseña de St. Jack\'s',
                $this->emailHtml($customer, $this->resetUrl($countryCode = $this->customerCountryCode($customer), $token), $countryCode),
            );
        } catch (Throwable $exception) {
            DB::table('stj_storefront_password_resets')->where('spr_token_hash', $tokenHash)->delete();
            Log::warning('No fue posible enviar el correo de recuperacion del storefront.', [
                'customer_id' => $customer->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function reset(string $token, string $password): bool
    {
        return DB::transaction(function () use ($token, $password): bool {
            $reset = DB::table('stj_storefront_password_resets')
                ->where('spr_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $reset || $reset->spr_used_at !== null || now()->greaterThan(Carbon::parse($reset->spr_expires_at))) return false;

            $customer = StorefrontCustomer::query()
                ->whereRaw('LOWER(usu_usuario) = ?', [strtolower($reset->spr_email)])
                ->where('usu_activo', 1)
                ->lockForUpdate()
                ->first();

            if (! $customer || Hash::check($password, $customer->getAuthPassword())) return false;

            $customer->forceFill(['usu_password' => Hash::make($password)])->save();
            $customer->tokens()->delete();

            DB::table('stj_storefront_password_resets')
                ->where('spr_id', $reset->spr_id)
                ->update(['spr_used_at' => now(), 'updated_at' => now()]);

            DB::table('stj_storefront_password_resets')
                ->where('spr_email', $reset->spr_email)
                ->where('spr_id', '<>', $reset->spr_id)
                ->delete();

            return true;
        });
    }

    private function resetUrl(string $country, string $token): string
    {
        return str_replace(
            ['{country}', '{token}'],
            [strtolower($country), $token],
            (string) config('services.storefront.password_reset_url'),
        );
    }

    private function ttlMinutes(): int
    {
        return max(5, min(120, (int) config('services.storefront.password_reset_ttl_minutes', 30)));
    }

    private function customerCountryCode(StorefrontCustomer $customer): string
    {
        if (! Schema::hasTable('stj_paises')) return 'SV';

        $countryId = filled($customer->usu_pais_registro) ? (int) $customer->usu_pais_registro : 1;
        $countryCode = DB::table('stj_paises')->where('pai_id', $countryId)->value('pai_codigo');

        if (blank($countryCode) && $countryId !== 1) {
            $countryCode = DB::table('stj_paises')->where('pai_id', 1)->value('pai_codigo');
        }

        return strtoupper(trim((string) $countryCode)) ?: 'SV';
    }

    private function storefrontUrl(string $country, string $path = ''): string
    {
        $base = str_replace('{country}', strtolower($country), (string) config('services.storefront.web_url'));

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private function emailHtml(StorefrontCustomer $customer, string $url, string $country): string
    {
        $name = e(trim((string) $customer->usu_nombre) ?: 'Hola');
        $safeUrl = e($url);
        $minutes = $this->ttlMinutes();
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
                <tr><td style="padding:36px 38px 32px">
                  <h1 style="font-size:26px;line-height:1.25;margin:0 0 16px;color:#0f172a">Crea una nueva contraseña</h1>
                  <p style="font-size:16px;line-height:1.65;margin:0 0 12px;color:#475569">Hola <strong>{$name}</strong>, recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>
                  <p style="margin:28px 0"><a href="{$safeUrl}" style="display:inline-block;background:#020617;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:14px 24px;border-radius:10px">Restablecer contraseña</a></p>
                  <p style="font-size:14px;line-height:1.65;margin:0;color:#64748b">Este enlace vence en {$minutes} minutos y solo puede utilizarse una vez. Si no solicitaste el cambio, ignora este correo; tu contraseña seguirá siendo la misma.</p>
                </td></tr>
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
}
