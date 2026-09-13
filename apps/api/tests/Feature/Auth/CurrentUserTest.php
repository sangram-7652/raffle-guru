<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_is_returned(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/auth/user');

        $response->assertOk()->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function test_guest_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    }

    public function test_sensitive_fields_are_never_returned(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/auth/user');

        $response->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }
}
