<?php

namespace Tests\Unit;

use App\Services\FirebasePushService;
use PHPUnit\Framework\TestCase;

class FirebaseInvalidTokenResultTest extends TestCase
{
    public function test_it_recognizes_only_definitive_invalid_token_responses(): void
    {
        $service = new FirebasePushService;

        $this->assertTrue($service->isInvalidTokenResult('{"error":{"status":"UNREGISTERED"}}'));
        $this->assertTrue($service->isInvalidTokenResult('registration-token-not-registered'));
        $this->assertTrue($service->isInvalidTokenResult('Requested entity was not found.'));
        $this->assertFalse($service->isInvalidTokenResult('UNAVAILABLE'));
        $this->assertFalse($service->isInvalidTokenResult('Deadline exceeded'));
    }
}
