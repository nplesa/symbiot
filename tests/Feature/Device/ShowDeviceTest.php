<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_own_device(): void
    {
        $user = User::factory()->create();

        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'manufacturer' => 'Samsung',
            'model' => 'Galaxy S24',
            'device_name' => 'My Phone',
            'os_version' => '15',
            'app_version' => '1.0.0',
            'battery' => 85,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/device')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'device' => [
                    'id' => $device->id,
                    'uuid' => $device->uuid,
                    'platform' => 'android',
                    'manufacturer' => 'Samsung',
                    'model' => 'Galaxy S24',
                    'device_name' => 'My Phone',
                    'battery' => 85,
                ],
            ]);
    }

    public function test_guest_cannot_view_device(): void
    {
        $this->getJson('/api/v1/device')
            ->assertUnauthorized();
    }

    public function test_user_cannot_view_another_users_device(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        Device::create([
            'user_id' => $owner->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
        ]);

        $this->actingAs($otherUser)
            ->getJson('/api/v1/device')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'device' => null,
            ]);
    }

    public function test_authenticated_user_without_device_gets_null_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/device')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'device' => null,
            ]);
    }
}