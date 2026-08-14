<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use App\Support\CouponProductScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CouponAudienceEmailService
{
    public function __construct(private readonly Smtp2GoMailer $mailer, private readonly CouponEmailConditions $conditions) {}

    /** @return array{pending:int,sent:int,failed:int,skipped:int} */
    public function sendPending(int $limit = 25): array
    {
        $summary = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $rows = DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->leftJoin('stj_paises as p', 'p.pai_id', '=', 'h.che_pais')
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'h.che_genero')
            ->leftJoin('stj_coleccion as collection', 'collection.col_id', '=', 'h.che_coleccion')
            ->whereIn('h.che_para', ['VIP', 'PLA'])
            ->where('h.che_estado', 'ACTIVO')->where('c.cup_estado', 'ACTIVO')
            ->where('c.cup_correo_enviado', 0)->whereNotNull('c.cup_correo')->where('c.cup_correo', '<>', '')
            ->where(fn ($q) => $q->whereNull('h.che_inicio')->orWhere('h.che_inicio', '<=', now()))
            ->where(fn ($q) => $q->whereNull('h.che_final')->orWhere('h.che_final', '>=', now()))
            ->orderBy('c.cup_id')->limit(max(1, min($limit, 25)))
            ->get(['c.cup_id', 'c.cup_codigo', 'c.cup_correo', 'c.cup_descuento', 'c.cup_monto', 'h.che_id as headerId', 'h.che_nombre', 'h.che_nombre_comercial', 'h.che_tipo', 'h.che_descuento', 'h.che_monto', 'h.che_final', 'h.che_aplica as channel', 'h.che_checkout as checkout', 'h.che_aplica_promo as promotionRule', 'h.che_aplica_monto_minimo as minimumEnabled', 'h.che_monto_minimo as minimumAmount', 'h.che_solo_primera_compra as firstPurchaseOnly', 'h.che_tipo_productos as productScope', 'h.che_multiple as multiple', 'h.che_coleccion as collectionId', 'category.cat_nombre as categoryName', 'collection.col_nombre as collectionName', 'p.pai_codigo']);

        $summary['pending'] = $rows->count();
        foreach ($rows as $coupon) {
            if ($this->isBounced((string) $coupon->cup_correo)) { $summary['skipped']++; continue; }
            $claimed = DB::table('stj_cupones')->where('cup_id', $coupon->cup_id)->where('cup_correo_enviado', 0)->update(['cup_correo_enviado' => 2]);
            if ($claimed !== 1) { $summary['skipped']++; continue; }

            try {
                $this->mailer->sendHtml((string) $coupon->cup_correo, 'Tienes un cupón disponible en St. Jack\'s', $this->html($coupon));
                DB::table('stj_cupones')->where('cup_id', $coupon->cup_id)->where('cup_correo_enviado', 2)->update(['cup_correo_enviado' => 1]);
                $summary['sent']++;
            } catch (Throwable $exception) {
                DB::table('stj_cupones')->where('cup_id', $coupon->cup_id)->where('cup_correo_enviado', 2)->update(['cup_correo_enviado' => 0]);
                $summary['failed']++;
                Log::error('No se pudo enviar correo de cupón VIP/lista.', ['coupon_id' => $coupon->cup_id, 'email' => $coupon->cup_correo, 'exception' => $exception->getMessage()]);
            }
        }
        return $summary;
    }

    private function isBounced(string $email): bool
    {
        return Schema::hasTable('correos_rebotados') && DB::table('correos_rebotados')->whereRaw('LOWER(correo) = ?', [strtolower(trim($email))])->exists();
    }

    private function html(object $coupon): string
    {
        $name = htmlspecialchars((string) ($coupon->che_nombre_comercial ?: $coupon->che_nombre ?: 'Cupón especial'), ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars((string) $coupon->cup_codigo, ENT_QUOTES, 'UTF-8');
        $benefit = match ((string) $coupon->che_tipo) {
            'DESCUENTO' => $this->number($coupon->cup_descuento ?? $coupon->che_descuento).' % de descuento',
            'PRECIO' => 'precio especial de USD '.$this->number($coupon->cup_monto ?? $coupon->che_monto),
            'ENVIO_GRATIS' => 'envío gratis',
            default => 'un beneficio especial',
        };
        $country = match (strtoupper((string) $coupon->pai_codigo)) { 'GT' => 'Guatemala', 'CR' => 'CostaRica', 'HN' => 'Honduras', 'PA' => 'Panama', default => 'ElSalvador' };
        $countryCode = strtolower((string) $coupon->pai_codigo);
        $validity = $coupon->che_final ? 'Válido hasta '.date('d/m/Y', strtotime((string) $coupon->che_final)) : 'Consulta sus condiciones en nuestro sitio web';
        $scope = CouponProductScope::details($coupon, $countryCode, (string) config('services.fcm.web_home_url', 'https://stjacks.com'));
        $productsLink = $scope['url'];
        $coupon->productScopeLabel = $scope['label'];

        return '<div style="max-width:600px;margin:auto;padding:24px;border:1px solid #eee;border-radius:8px;font-family:Arial,sans-serif;background:#f9f9f9;color:#333">'
            .'<h2 style="text-align:center;color:#0070c9">Tienes un cupón disponible</h2><p style="font-size:17px;text-align:center"><strong>'.$name.'</strong></p>'
            .'<div style="margin:22px 0;padding:22px;background:#e6f2ff;border:2px dashed #0070c9;border-radius:8px;text-align:center"><p style="font-size:18px">'.htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8').'</p><div style="font-size:28px;font-weight:bold;color:#ED174C">'.$code.'</div><p style="font-size:14px;color:#666">'.$validity.'</p></div>'
            .$this->conditions->html($coupon)
            .($productsLink ? '<p style="text-align:center;margin:20px 0"><a href="'.htmlspecialchars($productsLink, ENT_QUOTES, 'UTF-8').'" style="color:#0070c9;font-weight:bold;text-decoration:underline">Ver productos que aplican al cupón</a></p>' : '')
            .'<p style="text-align:center"><a href="https://stjacks.com/'.$country.'" style="display:inline-block;background:#ED174C;color:#fff;padding:12px 22px;border-radius:5px;text-decoration:none;font-weight:bold">Usar mi cupón</a></p></div>';
    }

    private function number(mixed $value): string { return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'); }

    private function localizedStorefrontUrl(string $countryCode): string
    {
        $base = rtrim((string) config('services.fcm.web_home_url', 'https://stjacks.com'), '/');
        $base = preg_replace('#/(sv|gt|cr|hn|pa)$#i', '', $base) ?: $base;
        return $base.'/'.strtolower($countryCode);
    }
}
