<?php

namespace App\Services;

class CouponEmailConditions
{
    /** @param array<string, mixed>|object $coupon */
    public function html(array|object $coupon): string
    {
        $value = fn (string $key, mixed $default = null) => is_array($coupon) ? ($coupon[$key] ?? $default) : ($coupon->{$key} ?? $default);
        $conditions = [];
        $conditions[] = match ((string) $value('channel', 'TODO')) {
            'WEB' => 'Válido únicamente en compras desde nuestro sitio web.',
            'APP', 'ANDROID', 'IOS' => 'Válido únicamente en compras desde nuestra aplicación.',
            default => 'Válido en web y aplicación, según disponibilidad del país.',
        };
        $conditions[] = match ((string) $value('checkout', 'TODO')) {
            'DOMICILIO' => 'Aplica únicamente para entrega a domicilio.',
            'TIENDA' => 'Aplica únicamente para retiro o compra en tienda.',
            default => 'Aplica para domicilio y tienda.',
        };
        $conditions[] = match ((string) $value('promotionRule', 'TODOS')) {
            'REGULAR' => 'Aplica únicamente a productos a precio regular; no aplica sobre promociones.',
            'PROMO' => 'Aplica únicamente a productos que tengan promoción.',
            default => 'Puede aplicar a productos a precio regular o con promoción.',
        };
        if ((string) $value('minimumEnabled', 'NO') === 'SI') $conditions[] = 'Compra mínima requerida: USD '.$this->number($value('minimumAmount', 0)).'.';
        if ((string) $value('firstPurchaseOnly', 'NO') === 'SI') $conditions[] = 'Válido únicamente para la primera compra del cliente.';
        if ($value('productScopeLabel')) $conditions[] = rtrim((string) $value('productScopeLabel'), '.').'.';
        if ((string) $value('multiple', 'NO') !== 'SI') $conditions[] = 'Cupón de un solo uso.';
        $conditions[] = 'El cupón es personal y está asociado al correo que recibió este mensaje.';

        return '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #ddd"><p style="font-size:15px;font-weight:bold;margin-bottom:8px">Condiciones del cupón</p><ul style="color:#444;font-size:14px;line-height:1.6;margin-top:0">'
            .collect($conditions)->map(fn ($condition) => '<li>'.htmlspecialchars($condition, ENT_QUOTES, 'UTF-8').'</li>')->implode('')
            .'</ul></div>';
    }

    private function number(mixed $value): string { return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'); }
}
