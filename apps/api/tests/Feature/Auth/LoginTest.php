<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonPath('data.email', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_login_fails_with_unknown_email_using_the_same_generic_message(): void
    {
        $known = User::factory()->create(['password' => 'password123']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $known->email,
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertUnprocessable();
        $unknownEmail->assertUnprocessable();
        $this->assertSame(
            $wrongPassword->json('message'),
            $unknownEmail->json('message'),
        );
    }

    public function test_unauthenticated_access_to_current_user_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    }
}
