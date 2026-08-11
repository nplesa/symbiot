<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class TokenExpirationConfigTest extends TestCase
{
    public function test_sanctum_tokens_have_a_default_expiration(): void
    {
        $this->assertSame(43200, (int) config('sanctum.expiration'));
    }
}
