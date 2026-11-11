<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'name' => 'Ana',
            'email' => 'old@example.test',
            'password' => Hash::make('secret-password'),
        ]);
    }

    public function test_profile_can_be_updated_without_password_when_email_is_unchanged(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->actingAs($user)->put(route('app.profile.update'), [
            'name' => 'Ana Maria',
            'email' => 'old@example.test',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ana Maria', $user->fresh()->name);
        Notification::assertNothingSent();
    }

    public function test_email_change_requires_the_current_password(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->actingAs($user)->put(route('app.profile.update'), [
            'name' => 'Ana',
            'email' => 'attacker@example.test',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('app.profile.update'), [
            'name' => 'Ana',
            'email' => 'attacker@example.test',
            'current_password' => 'wrong-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame('old@example.test', $user->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_email_change_with_password_notifies_the_previous_address(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->actingAs($user)->put(route('app.profile.update'), [
            'name' => 'Ana',
            'email' => 'new@example.test',
            'current_password' => 'secret-password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);

        Notification::assertSentOnDemand(
            EmailChangedNotification::class,
            function (EmailChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                $mail = $notification->toMail($notifiable);

                return $notifiable->routes['mail'] === 'old@example.test'
                    && str_contains(implode(' ', $mail->introLines), 'new@example.test');
            }
        );
    }

    public function test_mail_failure_does_not_undo_the_email_change(): void
    {
        config(['mail.default' => 'failing']);
        config(['mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $user = $this->user();

        $this->actingAs($user)->put(route('app.profile.update'), [
            'name' => 'Ana',
            'email' => 'new@example.test',
            'current_password' => 'secret-password',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('new@example.test', $user->fresh()->email);
    }
}
