<?php

namespace Tests\Feature\Location;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_location(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/location/update', [
                'lat' => 45.6486,
                'lon' => 25.6061,
                'heading' => 180,
                'active' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $cached = cache()->get("user_location_{$user->id}");

        $this->assertNotNull($cached);
        $this->assertEquals(45.6486, $cached['lat']);
        $this->assertEquals(25.6061, $cached['lon']);
        $this->assertEquals(180, $cached['heading']);
        $this->assertTrue($cached['active']);
        $this->assertArrayHasKey('updated_at', $cached);
    }

    public function test_second_update_overwrites_previous_location(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/location/update', [
                'lat' => 45,
                'lon' => 25,
                'heading' => 90,
                'active' => true,
            ]);

        $this->actingAs($user)
            ->post('/location/update', [
                'lat' => 46,
                'lon' => 26,
                'heading' => 180,
                'active' => false,
            ]);

        $cached = cache()->get("user_location_{$user->id}");

        $this->assertEquals(46, $cached['lat']);
        $this->assertEquals(26, $cached['lon']);
        $this->assertEquals(180, $cached['heading']);
        $this->assertFalse($cached['active']);
    }

    public function test_guest_cannot_update_location(): void
    {
        $this->post('/location/update')
            ->assertRedirect('/login');
    }

    public function test_latitude_must_be_between_minus_90_and_90(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => 91,
                'lon' => 25.0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lat');
    }

    public function test_latitude_cannot_be_less_than_minus_90(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => -91,
                'lon' => 25.0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lat');
    }

    public function test_longitude_must_be_between_minus_180_and_180(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => 45.0,
                'lon' => 181,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lon');
    }

    public function test_longitude_cannot_be_less_than_minus_180(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => 45.0,
                'lon' => -181,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lon');
    }

    public function test_heading_must_be_between_zero_and_360(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => 45.0,
                'lon' => 25.0,
                'heading' => 361,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('heading');
    }

    public function test_heading_cannot_be_negative(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => 45.0,
                'lon' => 25.0,
                'heading' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('heading');
    }

    public function test_active_must_be_boolean(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/location/update', [
                'lat' => 45.0,
                'lon' => 25.0,
                'active' => 'not-a-boolean',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active');
    }

}
