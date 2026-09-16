<?php

namespace Tests\Unit;

use App\Services\Mail\Smtp2GoMailer;
use App\Services\Mail\StorefrontMailTemplate;
use App\Services\StorefrontOrderConfirmationEmailService;
use Tests\TestCase;

class StorefrontOrderConfirmationEmailContentTest extends TestCase
{
    public function test_store_pickup_email_shows_reservation_and_authorized_person(): void
    {
        $html = $this->content('TIENDA', 'NO');

        $this->assertStringContainsString('reservado durante 48 horas', $html);
        $this->assertStringContainsString('Ana López', $html);
        $this->assertStringContainsString('+503 77067440', $html);
        $this->assertStringContainsString('12345678-9', $html);
    }

    public function test_delivery_email_does_not_show_pickup_notice_or_person(): void
    {
        $html = $this->content('DOMICILIO', 'NO');

        $this->assertStringNotContainsString('reservado durante 48 horas', $html);
        $this->assertStringNotContainsString('Ana López', $html);
    }

    public function test_pickup_by_customer_shows_reservation_without_other_person(): void
    {
        $html = $this->content('TIENDA', 'SI');

        $this->assertStringContainsString('reservado durante 48 horas', $html);
        $this->assertStringNotContainsString('Ana López', $html);
    }

    private function content(string $checkout, string $samePerson): string
    {
        $service = new StorefrontOrderConfirmationEmailService(new Smtp2GoMailer, new StorefrontMailTemplate);
        $order = (object) [
            'ped_nombres' => 'Cliente', 'ped_apellidos' => 'Prueba', 'ped_checkout' => $checkout,
            'dir_direccion' => 'Calle 1', 'dir_municipio_txt' => 'San Salvador', 'dir_departamento_txt' => 'San Salvador',
            'tie_nombre' => 'Tienda Centro', 'pai_codigo' => 'SV', 'ppa_tipo' => 'TARJETA',
            'ppa_autorizacion' => null, 'ppa_monto_sdesc' => '10.00', 'ppa_monto_senv' => '10.00',
            'ppa_monto' => '10.00', 'ppa_ref' => 'STJ123', 'ped_telefono' => '70000000',
            'ped_telefono_pais' => '503', 'pti_misma_persona' => $samePerson,
            'pti_persona' => 'Ana López', 'pti_telefono' => '77067440',
            'pti_identificacion' => '12345678-9', 'pickup_phonecode' => '503',
        ];

        return (new \ReflectionMethod($service, 'customerContent'))->invoke($service, $order, collect());
    }
}
