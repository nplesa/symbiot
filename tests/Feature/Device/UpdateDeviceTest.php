<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_own_device(): void
    {
        $user = User::factory()->create();

        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'manufacturer' => 'Samsung',
            'model' => 'Old Model',
            'device_name' => 'Old Name',
            'battery' => 50,
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/device', [
                'model' => 'Galaxy S24',
                'device_name' => 'My Phone',
                'battery' => 90,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'device' => [
                    'id' => $device->id,
                    'model' => 'Galaxy S24',
                    'device_name' => 'My Phone',
                    'battery' => 90,
                ],
            ]);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'model' => 'Galaxy S24',
            'device_name' => 'My Phone',
            'battery' => 90,
        ]);
    }

    public function test_guest_cannot_update_device(): void
    {
        $this->putJson('/api/v1/device', [
            'battery' => 90,
        ])->assertUnauthorized();
    }

    public function test_update_returns_404_when_user_has_no_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/v1/device', [
                'battery' => 90,
            ])
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'Device not found.',
            ]);
    }

    public function test_user_cannot_update_another_users_device(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $device = Device::create([
            'user_id' => $owner->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'battery' => 50,
        ]);

        $this->actingAs($otherUser)
            ->putJson('/api/v1/device', [
                'battery' => 100,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'battery' => 50,
        ]);
    }

    public function test_uuid_cannot_be_changed(): void
    {
        $user = User::factory()->create();

        $uuid = (string) Str::uuid();
        $newUuid = (string) Str::uuid();

        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => $uuid,
            'platform' => 'android',
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/device', [
                'uuid' => $newUuid,
            ])
            ->assertOk();

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'uuid' => $uuid,
        ]);

        $this->assertDatabaseMissing('devices', [
            'id' => $device->id,
            'uuid' => $newUuid,
        ]);
    }

    public function test_battery_must_be_between_zero_and_one_hundred(): void
    {
        $user = User::factory()->create();

        Device::create([
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'battery' => 50,
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/device', [
                'battery' => 101,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('battery');
    }

    public function test_platform_must_be_android_or_ios(): void
    {
        $user = User::factory()->create();

        Device::create([
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/device', [
                'platform' => 'windows',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }
}