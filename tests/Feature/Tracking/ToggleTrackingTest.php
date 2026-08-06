<?php

namespace Tests\Feature\Tracking;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToggleTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enable_tracking(): void
    {
        $user = User::factory()->create([
            'tracking' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('app.user.tracking.toggle'), [
                'trackingmyself' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'tracking' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'tracking' => true,
        ]);
    }

    public function test_user_can_disable_tracking(): void
    {
        $user = User::factory()->create([
            'tracking' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('app.user.tracking.toggle'), [
                'trackingmyself' => false,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'tracking' => false,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'tracking' => false,
        ]);
    }

    public function test_trackingmyself_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.user.tracking.toggle'), []);

        $response->assertSessionHasErrors('trackingmyself');
    }

    public function test_trackingmyself_must_be_boolean(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.user.tracking.toggle'), [
                'trackingmyself' => 'abc',
            ]);

        $response->assertSessionHasErrors('trackingmyself');
    }

    public function test_guest_cannot_toggle_tracking(): void
    {
        $this->post(route('app.user.tracking.toggle'))
            ->assertRedirect('/login');
    }
}
