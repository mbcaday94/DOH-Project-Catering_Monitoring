<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_login_form_and_cannot_open_the_dashboard(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('doh-seal.png');
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_user_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->getRememberToken());
        $this->get(route('dashboard'))->assertOk()->assertSee($user->name);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_invalid_credentials_return_an_error_and_keep_the_user_signed_out(): void
    {
        $user = User::factory()->create();

        $this->from(route('login'))->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'incorrect-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('login')
            ->assertSessionHasInput('login', $user->email);

        $this->assertGuest();
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_a_user_can_sign_in_with_a_username(): void
    {
        $user = User::factory()->create(['username' => 'portal-staff']);

        $this->post(route('login.store'), [
            'login' => 'portal-staff',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_portal_command_creates_an_account_with_a_hashed_password(): void
    {
        $this->artisan('portal:create-user')
            ->expectsQuestion('Name', 'Portal Staff')
            ->expectsQuestion('Email address', 'staff@example.com')
            ->expectsQuestion('Password', 'test-portal-password')
            ->expectsQuestion('Confirm password', 'test-portal-password')
            ->assertSuccessful();

        $this->assertCredentials([
            'email' => 'staff@example.com',
            'password' => 'test-portal-password',
        ]);
    }
}
