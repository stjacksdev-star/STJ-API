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
}
