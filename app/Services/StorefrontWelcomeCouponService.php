<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class StorefrontWelcomeCouponService
{
    private const TEMPLATE = 'REGISTRO_EMAIL';

    private const VALIDITY_DAYS = 15;

    public function __construct(private readonly Smtp2GoMailer $mailer, private readonly CouponEmailConditions $conditions) {}

    /** @return array<string, mixed>|null */
    public function issue(int $countryId, string $countryCode, string $email, string $customerName): ?array
    {
        $email = strtolower(trim($email));
        $template = DB::table('stj_cupones_header')
            ->where('che_config_automatica', self::TEMPLATE)
            ->where('che_estado', 'ACTIVO')
            ->where(function ($query) use ($countryId) {
                $query->where('che_pais', $countryId)->orWhere('che_regional', 'SI');
            })
            ->orderByRaw('che_pais = ? desc', [$countryId])
            ->orderByDesc('che_id')
            ->lockForUpdate()
            ->first();

        if (! $template) {
            Log::warning('No se encontró la plantilla automática de cupón de registro.', [
                'template' => self::TEMPLATE,
                'country_id' => $countryId,
                'email' => $email,
            ]);

            return null;
        }

        $now = now();
        $header = (array) $template;
        unset($header['che_id']);
        $header['che_generico'] = 'NO';
        $header['che_pais'] = $countryId;
        $header['che_inicio'] = $now;
        $header['che_final'] = $now->copy()->addDays(self::VALIDITY_DAYS);
        $header['che_config_automatica'] = null;
        $header['che_estado'] = 'ACTIVO';

        $headerId = DB::table('stj_cupones_header')->insertGetId($header);
        $code = $this->uniqueCode();
        $couponId = DB::table('stj_cupones')->insertGetId([
            'cup_header' => $headerId,
            'cup_codigo' => $code,
            'cup_estado' => 'ACTIVO',
            'cup_fecha' => $now,
            'cup_vigencia' => self::VALIDITY_DAYS,
            'cup_monto' => $template->che_monto,
            'cup_descuento' => $template->che_descuento,
            'cup_multiple' => $template->che_multiple ?: 'NO',
            'cup_disponible' => $template->che_monto,
            'cup_pais' => in_array(strtoupper($countryCode), ['SV', 'GT', 'CR'], true) ? strtoupper($countryCode) : null,
            'cup_aplica_monto_minimo' => $template->che_aplica_monto_minimo,
            'cup_monto_minimo' => $template->che_monto_minimo,
            'cup_correo' => $email,
            'cup_correo_enviado' => 0,
        ]);

        if (Schema::hasTable('stj_cupones_producto')) {
            $products = DB::table('stj_cupones_producto')->where('cpr_cupon', $template->che_id)->get();
            foreach ($products as $product) {
                $copy = (array) $product;
                unset($copy['cpr_id']);
                $copy['cpr_cupon'] = $headerId;
                DB::table('stj_cupones_producto')->insert($copy);
            }
        }

        return [
            'id' => $couponId,
            'headerId' => $headerId,
            'code' => $code,
            'email' => $email,
            'customerName' => trim($customerName),
            'countryCode' => strtoupper($countryCode),
            'type' => $template->che_tipo,
            'discount' => (float) $template->che_descuento,
            'amount' => (float) $template->che_monto,
            'endsAt' => $header['che_final'],
            'channel' => $template->che_aplica,
            'checkout' => $template->che_checkout,
            'promotionRule' => $template->che_aplica_promo,
            'minimumEnabled' => $template->che_aplica_monto_minimo,
            'minimumAmount' => $template->che_monto_minimo,
            'firstPurchaseOnly' => $template->che_solo_primera_compra,
            'productScope' => $template->che_tipo_productos,
            'multiple' => $template->che_multiple,
        ];
    }

    /** @param array<string, mixed>|null $coupon */
    public function sendWelcomeEmail(?array $coupon): void
    {
        if (! $coupon || $this->isBounced($coupon['email'])) return;

        try {
            $this->mailer->sendHtml(
                [$coupon['customerName'] ?: 'Cliente' => $coupon['email']],
                "🎁 ¡Bienvenido a St. Jack's! Aquí tienes tu cupón de descuento",
                $this->emailHtml($coupon),
            );
            DB::table('stj_cupones')->where('cup_id', $coupon['id'])->update(['cup_correo_enviado' => 1]);
        } catch (Throwable $exception) {
            Log::error('No se pudo enviar el cupón de bienvenida.', [
                'coupon_id' => $coupon['id'],
                'email' => $coupon['email'],
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = strtoupper(bin2hex(random_bytes(3)));
            if (! DB::table('stj_cupones')->where('cup_codigo', $code)->exists()) return $code;
        }

        throw new RuntimeException('No fue posible generar un código de cupón único.');
    }

    private function isBounced(string $email): bool
    {
        return Schema::hasTable('correos_rebotados')
            && DB::table('correos_rebotados')->where('correo', $email)->exists();
    }

    /** @param array<string, mixed> $coupon */
    private function emailHtml(array $coupon): string
    {
        $code = htmlspecialchars((string) $coupon['code'], ENT_QUOTES, 'UTF-8');
        $benefit = $coupon['type'] === 'DESCUENTO'
            ? rtrim(rtrim(number_format((float) $coupon['discount'], 2, '.', ''), '0'), '.').' % de descuento'
            : 'un beneficio especial';
        $countryPath = match ($coupon['countryCode'] ?? 'SV') {
            'GT' => 'Guatemala',
            'CR' => 'CostaRica',
            'HN' => 'Honduras',
            'PA' => 'Panama',
            default => 'ElSalvador',
        };
        $shopUrl = 'https://stjacks.com/'.$countryPath;

        return '<div style="max-width:600px;margin:auto;padding:20px;border:1px solid #eee;border-radius:8px;font-family:sans-serif;background:#f9f9f9">'
            .'<h2 style="color:#007bff;text-align:center">¡Gracias por registrarte en St. Jack\'s! 🎁</h2>'
            .'<p style="font-size:16px;color:#333">Como regalo de bienvenida, te hemos preparado un cupón exclusivo:</p>'
            .'<div style="margin:20px 0;padding:20px;background:#e6f2ff;border:2px dashed #007bff;border-radius:6px;text-align:center">'
            .'<p style="font-size:18px;color:#333">Cupón de <strong>'.htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8').'</strong></p>'
            .'<div style="font-size:24px;font-weight:bold;color:#ED174C">'.$code.'</div>'
            .'<p style="font-size:14px;color:#666">Válido por '.self::VALIDITY_DAYS.' días a partir de hoy</p></div>'
            .$this->conditions->html($coupon)
            .'<p style="text-align:center;margin-top:30px"><a href="'.$shopUrl.'" style="background:#ED174C;color:white;padding:12px 20px;border-radius:5px;text-decoration:none;font-weight:bold">Usar mi cupón ahora</a></p>'
            .'<p style="font-size:13px;color:#999;text-align:center;margin-top:30px">Este cupón es personal y está asociado a tu correo.</p></div>';
    }
}
