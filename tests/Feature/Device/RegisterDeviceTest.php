<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisterDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_new_device(): void
    {
        $user = User::factory()->create();

        $uuid = (string) Str::uuid();

        $response = $this
            ->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'uuid' => $uuid,
                'platform' => 'android',
                'manufacturer' => 'Samsung',
                'model' => 'Galaxy S24',
                'device_name' => 'My Phone',
                'os_version' => '15',
                'app_version' => '1.0.0',
                'push_token' => 'test-push-token',
                'battery' => 85,
            ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'device_id',
            ]);

        $this->assertDatabaseHas('devices', [
            'uuid' => $uuid,
            'user_id' => $user->id,
            'platform' => 'android',
            'manufacturer' => 'Samsung',
            'model' => 'Galaxy S24',
            'device_name' => 'My Phone',
            'os_version' => '15',
            'app_version' => '1.0.0',
            'push_token' => 'test-push-token',
            'battery' => 85,
        ]);
    }

    public function test_guest_cannot_register_device(): void
    {
        $this->postJson('/api/v1/device/register', [
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
        ])
            ->assertUnauthorized();
    }

    public function test_uuid_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'platform' => 'android',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');
    }

    public function test_uuid_must_be_valid_uuid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'uuid' => 'not-a-uuid',
                'platform' => 'android',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('uuid');
    }

    public function test_platform_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    public function test_platform_must_be_android_or_ios(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'uuid' => (string) Str::uuid(),
                'platform' => 'windows',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    public function test_battery_must_be_between_zero_and_one_hundred(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'uuid' => (string) Str::uuid(),
                'platform' => 'android',
                'battery' => 101,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('battery');
    }

    public function test_device_uuid_cannot_be_registered_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $uuid = (string) Str::uuid();

        Device::create([
            'user_id' => $owner->id,
            'uuid' => $uuid,
            'platform' => 'android',
        ]);

        $this->actingAs($otherUser)
            ->postJson('/api/v1/device/register', [
                'uuid' => $uuid,
                'platform' => 'android',
            ])
            ->assertStatus(409);
    }

    public function test_existing_device_can_be_registered_again_by_same_user(): void
    {
        $user = User::factory()->create();

        $uuid = (string) Str::uuid();

        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => $uuid,
            'platform' => 'android',
            'battery' => 50,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/v1/device/register', [
                'uuid' => $uuid,
                'platform' => 'android',
                'battery' => 90,
            ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'device_id' => $device->id,
            ]);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'user_id' => $user->id,
            'uuid' => $uuid,
            'battery' => 90,
        ]);
    }
}