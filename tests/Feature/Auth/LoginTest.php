<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $password = 'password123';

        $user = User::factory()->create([
            'password' => bcrypt($password),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $response->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_user_cannot_login_with_unknown_email(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'missing@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_email_and_password_are_required(): void
    {
        $response = $this->from('/login')->post('/login', []);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email']);
    }
}
