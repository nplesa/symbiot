<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_or_missing_email_is_a_validation_error_not_a_server_error(): void
    {
        $this->postJson('/api/v1/login', ['email' => ['x'], 'password' => 'secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/login', ['password' => 'secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_email_case_and_whitespace_variants_share_the_login_limit(): void
    {
        foreach (['ana@example.test', 'ANA@example.test', ' Ana@Example.test ', 'ana@EXAMPLE.test', 'Ana@example.test'] as $email) {
            $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/login', ['email' => 'aNa@example.test', 'password' => 'wrong-password'])
            ->assertTooManyRequests();

        // A different account from the same IP is still allowed.
        $this->postJson('/api/v1/login', ['email' => 'other@example.test', 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }
}
