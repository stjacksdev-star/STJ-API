<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Services\Mail\Smtp2GoMailer;
use App\Services\Mail\StorefrontMailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StorefrontPasswordResetService
{
    public function __construct(
        private readonly Smtp2GoMailer $mailer,
        private readonly StorefrontMailTemplate $template,
    ) {}

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

    private function emailHtml(StorefrontCustomer $customer, string $url, string $country): string
    {
        $name = e(trim((string) $customer->usu_nombre) ?: 'Hola');
        $safeUrl = e($url);
        $minutes = $this->ttlMinutes();

        $content = <<<HTML
        <h1 style="font-size:26px;line-height:1.25;margin:0 0 16px;color:#0f172a">Crea una nueva contraseña</h1>
        <p style="font-size:16px;line-height:1.65;margin:0 0 12px;color:#475569">Hola <strong>{$name}</strong>, recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>
        <p style="margin:28px 0"><a href="{$safeUrl}" style="display:inline-block;background:#020617;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:14px 24px;border-radius:10px">Restablecer contraseña</a></p>
        <p style="font-size:14px;line-height:1.65;margin:0;color:#64748b">Este enlace vence en {$minutes} minutos y solo puede utilizarse una vez. Si no solicitaste el cambio, ignora este correo; tu contraseña seguirá siendo la misma.</p>
        HTML;

        return $this->template->render($content, $country);
    }
}
