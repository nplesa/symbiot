<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordChangeLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'ana@example.test',
            'password' => Hash::make('old-password-1'),
        ]);
    }

    public function test_changing_password_logs_out_other_browser_sessions_but_keeps_the_current_one(): void
    {
        $this->withoutVite();
        $user = $this->user();
        $oldHash = $user->password;

        $this->actingAs($user)->put(route('app.profile.password'), [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertSessionHasNoErrors();

        // The session that changed the password stored the new hash and keeps working.
        $this->actingAs($user->fresh())->get(route('app.profile.edit'))->assertOk();

        // Another browser still carries the old password hash in its session.
        $this->flushSession();
        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $oldHash])
            ->get(route('app.profile.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_changing_password_revokes_mobile_api_tokens(): void
    {
        $user = $this->user();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->actingAs($user)->put(route('app.profile.password'), [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/tracking/status')->assertUnauthorized();
    }

    public function test_forgotten_password_reset_revokes_mobile_api_tokens(): void
    {
        $user = $this->user();
        $user->createToken('mobile');

        $this->post('/password/reset', [
            'token' => Password::createToken($user),
            'email' => 'ana@example.test',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
