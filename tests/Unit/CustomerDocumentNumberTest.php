<?php

namespace Tests\Unit;

use App\Support\CustomerDocumentNumber;
use PHPUnit\Framework\TestCase;

class CustomerDocumentNumberTest extends TestCase
{
    public function test_dui_accepts_nine_digits_with_or_without_hyphen_and_normalizes(): void
    {
        $this->assertTrue(CustomerDocumentNumber::valid('SV', 'DUI', '01234567-8'));
        $this->assertTrue(CustomerDocumentNumber::valid('SV', 'DUI', '012345678'));
        $this->assertSame('012345678', CustomerDocumentNumber::normalize('SV', 'DUI', '01234567-8'));
        $this->assertFalse(CustomerDocumentNumber::valid('SV', 'DUI', '0123456789'));
        $this->assertFalse(CustomerDocumentNumber::valid('GT', 'DUI', '012345678'));
    }

    public function test_dpi_requires_thirteen_digits_and_other_documents_remain_flexible(): void
    {
        $this->assertTrue(CustomerDocumentNumber::valid('GT', 'DPI', '1234 56789 0101'));
        $this->assertSame('1234567890101', CustomerDocumentNumber::normalize('GT', 'DPI', '1234 56789 0101'));
        $this->assertFalse(CustomerDocumentNumber::valid('GT', 'DPI', '123456789012'));
        $this->assertTrue(CustomerDocumentNumber::valid('SV', 'Pasaporte', 'A12345'));
        $this->assertContains('DNI', CustomerDocumentNumber::types('HN'));
        $this->assertNotContains('DPI', CustomerDocumentNumber::types('SV'));
    }

    public function test_costa_rican_cedula_and_honduran_dni_use_national_lengths(): void
    {
        $this->assertTrue(CustomerDocumentNumber::valid('CR', 'Cédula', '1-2345-6789'));
        $this->assertSame('123456789', CustomerDocumentNumber::normalize('CR', 'Cédula', '1-2345-6789'));
        $this->assertFalse(CustomerDocumentNumber::valid('CR', 'Cédula', '12345678'));
        $this->assertTrue(CustomerDocumentNumber::valid('HN', 'DNI', '0801-1990-12345'));
        $this->assertSame('0801199012345', CustomerDocumentNumber::normalize('HN', 'DNI', '0801-1990-12345'));
        $this->assertFalse(CustomerDocumentNumber::valid('HN', 'DNI', '080119901234'));
    }

    public function test_panamanian_cedula_has_no_fixed_digit_length_rule(): void
    {
        $this->assertTrue(CustomerDocumentNumber::valid('PA', 'Cédula', '8-123-456'));
        $this->assertTrue(CustomerDocumentNumber::valid('PA', 'Cédula', 'E-8-12345'));
    }
}
