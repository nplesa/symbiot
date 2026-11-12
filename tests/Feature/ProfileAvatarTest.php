<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacing_the_avatar_stores_the_new_file_before_removing_the_old_one(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('app.profile.avatar'), ['avatar' => UploadedFile::fake()->image('a.png')]);
        $first = $user->profile()->value('avatar');
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)->post(route('app.profile.avatar'), ['avatar' => UploadedFile::fake()->image('b.png')]);
        $second = $user->profile()->value('avatar');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_opening_the_profile_page_does_not_create_a_profile_row(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('app.profile.edit'))->assertOk();

        $this->assertSame(0, DB::table('user_profiles')->where('user_id', $user->id)->count());
    }

    public function test_deleting_a_user_from_admin_removes_the_avatar_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('app.profile.avatar'), ['avatar' => UploadedFile::fake()->image('a.png')]);
        $avatar = $user->profile()->value('avatar');

        Livewire::actingAs($admin)->test(Users::class)->call('delete', $user->id);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('public')->assertMissing($avatar);
    }
}
