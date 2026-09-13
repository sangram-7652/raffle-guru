<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function signedVerificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }

    public function test_a_valid_signed_link_verifies_the_email(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get($this->signedVerificationUrl($user));

        $response->assertRedirect();
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_an_invalid_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('not-the-real-email'),
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->subMinutes(1), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_visiting_the_link_while_already_verified_is_handled_gracefully(): void
    {
        $user = User::factory()->create(); // already verified by default

        $response = $this->actingAs($user)->get($this->signedVerificationUrl($user));

        $response->assertRedirect();
        $this->assertStringContainsString('status=already-verified', $response->headers->get('Location'));
    }

    public function test_verification_link_requires_authentication(): void
    {
        $user = User::factory()->unverified()->create();

        // This path matches api/* (see bootstrap/app.php shouldRenderJsonWhen),
        // so a guest gets a JSON 401, not a redirect to a login page.
        $response = $this->get($this->signedVerificationUrl($user));

        $response->assertUnauthorized();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_sends_a_new_verification_notification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification');

        $response->assertOk();
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
    }

    public function test_resend_is_a_no_op_when_already_verified(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification');

        $response->assertOk();
        Notification::assertNotSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
    }
}
