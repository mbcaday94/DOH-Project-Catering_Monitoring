<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_picture_can_be_uploaded_viewed_and_replaced(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $update = route('dashboard.profile.update');
        $picture = route('dashboard.profile.picture');
        $overview = route('dashboard', ['view' => 'overview']);

        $this->actingAs($user)->post($update, [
            '_method' => 'PATCH',
            'name' => $user->name,
            'profile_picture' => UploadedFile::fake()->image('profile.png'),
        ])->assertRedirect($overview);

        $path = $user->fresh()->profile_picture_path;
        Storage::disk('local')->assertExists($path);
        $this->get($overview)->assertOk()->assertSee($picture)->assertDontSee($path);
        $this->get($picture)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->patch($update, ['name' => 'Updated name'])->assertRedirect($overview);
        $this->assertSame($path, $user->fresh()->profile_picture_path);

        $this->post($update, [
            '_method' => 'PATCH',
            'name' => 'Updated name',
            'profile_picture' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertRedirect($overview);
        Storage::disk('local')->assertMissing($path);
        $this->get($picture)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs(User::factory()->create())->get($picture)->assertNotFound();
    }

    public function test_users_can_update_their_profile_and_add_private_schedule_entries(): void
    {
        $overview = route('dashboard', ['view' => 'overview']);
        $user = User::factory()->create();
        $this->actingAs($user)->get($overview)->assertOk()
            ->assertSee(['User Profile', 'Designation', 'Work Status', 'Upcoming Events', 'Calendar', 'My personal and work schedule', 'No upcoming events.'])
            ->assertDontSee(['Recently added records', 'Recorded PO value']);

        $this->patch(route('dashboard.profile.update'), [
            'name' => 'Grace Example',
            'designation' => 'Supply Officer',
            'work_status' => 'On duty',
        ])->assertRedirect($overview);

        $date = now('Asia/Manila')->addDays(2);
        $calendar = route('dashboard', ['view' => 'overview', 'month' => $date->format('Y-m')]);
        $this->post(route('dashboard.schedule.store'), [
            'title' => 'Work planning',
            'category' => 'work',
            'starts_on' => $date->toDateString(),
            'starts_at' => '09:30',
        ])->assertRedirect($calendar);
        $this->post(route('dashboard.schedule.store'), [
            'title' => 'Personal appointment',
            'category' => 'personal',
            'starts_on' => $date->toDateString(),
        ])->assertRedirect($calendar);

        $this->get($calendar)->assertOk()
            ->assertSee(['Grace Example', 'Supply Officer', 'On duty', $date->format('F Y'), 'Work planning', '09:30', 'Personal appointment']);
        $this->assertDatabaseHas('schedule_entries', ['user_id' => $user->id, 'category' => 'personal']);

        $this->actingAs(User::factory()->create())->get($calendar)->assertOk()
            ->assertDontSee(['Work planning', 'Personal appointment', 'Supply Officer']);
    }
}
