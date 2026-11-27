<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileLoginRateLimitTest extends TestCase
{
    public function test_mobile_login_has_its_own_rate_limit(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/mobile/v1/visits')->assertUnprocessable();
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/mobile/v1/auth/login')->assertUnprocessable();
        }

        $this->postJson('/api/mobile/v1/auth/login')->assertStatus(429);
        $this->postJson('/api/mobile/v1/visits')->assertUnprocessable();
    }
}
