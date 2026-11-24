<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_session_can_be_created_read_and_deleted(): void
    {
        $user = User::factory()->create(['username' => 'portal-staff']);
        $session = route('api.v1.session.show');
        $credentials = ['login' => $user->username, 'password' => 'password'];

        $this->get($session)->assertUnauthorized()->assertJsonStructure(['message']);

        $this->postJson($session, $credentials)->assertOk()->assertExactJson([
            'data' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username],
        ]);

        $this->getJson($session)->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson($session, $credentials)->assertConflict();

        $this->deleteJson($session)->assertNoContent();
        $this->getJson($session)->assertUnauthorized();
    }

    public function test_json_sign_in_returns_validation_errors(): void
    {
        $this->postJson(route('api.v1.session.store'), [
            'login' => 'unknown-user',
            'password' => 'incorrect-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('login');

        $this->assertGuest();
    }
}
