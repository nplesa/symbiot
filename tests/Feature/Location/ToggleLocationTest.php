<?php

namespace Tests\Feature\Location;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToggleLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enable_location(): void
    {
        $user = User::factory()->create([
            'location' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('app.user.location.toggle'), [
                'location' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'location' => true,
        ]);
    }

    public function test_user_can_disable_location(): void
    {
        $user = User::factory()->create([
            'location' => true,
        ]);

        $this->actingAs($user)
            ->post(route('app.user.location.toggle'), [
                'location' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'location' => false,
        ]);
    }

    public function test_location_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('app.user.location.toggle'), [])
            ->assertSessionHasErrors('location');
    }

    public function test_location_must_be_boolean(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('app.user.location.toggle'), [
                'location' => 'abc',
            ])
            ->assertSessionHasErrors('location');
    }

    public function test_guest_cannot_toggle_location(): void
    {
        $this->post(route('app.user.location.toggle'))
            ->assertRedirect('/login');
    }
}
