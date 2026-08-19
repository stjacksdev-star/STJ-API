<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
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
                $this->emailHtml($customer, $this->resetUrl($country, $token)),
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

    private function emailHtml(StorefrontCustomer $customer, string $url): string
    {
        $name = e(trim((string) $customer->usu_nombre) ?: 'Hola');
        $safeUrl = e($url);
        $minutes = $this->ttlMinutes();

        return <<<HTML
        <div style="margin:0;background:#f8fafc;padding:32px 16px;font-family:Arial,sans-serif;color:#0f172a">
          <div style="max-width:560px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:32px">
            <img src="https://stj-assets.sfo3.cdn.digitaloceanspaces.com/logos/stjecommerce/logo%20st%20jacks.svg" alt="St. Jack's" width="150" style="display:block;margin-bottom:28px">
            <h1 style="font-size:24px;margin:0 0 12px">Crea una nueva contraseña</h1>
            <p style="line-height:1.6;color:#475569">Hola <strong>{$name}</strong>, recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>
            <p style="margin:28px 0"><a href="{$safeUrl}" style="display:inline-block;background:#020617;color:#fff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:12px">Restablecer contraseña</a></p>
            <p style="line-height:1.6;color:#64748b;font-size:14px">Este enlace vence en {$minutes} minutos y solo puede utilizarse una vez. Si no solicitaste el cambio, ignora este correo; tu contraseña seguirá siendo la misma.</p>
          </div>
        </div>
        HTML;
    }
}
