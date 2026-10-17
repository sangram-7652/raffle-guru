<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
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

    // ---- The actual regression pin --------------------------------
    //
    // The bug (see docs/EMAIL_VERIFICATION.md "Verification link
    // routing/auth fix") was that 'auth:sanctum' on this route only
    // authenticates when Sanctum's EnsureFrontendRequestsAreStateful::
    // fromFrontend() considers the request's Referer/Origin header a
    // match for SANCTUM_STATEFUL_DOMAINS — which a real top-level
    // navigation from an email client essentially never is. Verified
    // empirically against the real multi-process Docker stack with curl
    // (a genuine session cookie + no/mismatched Referer failed before
    // this fix, succeeded after — see that doc for the exact commands).
    //
    // That specific mechanism can't be reproduced faithfully in a
    // PHPUnit Feature test: Laravel's HTTP test client shares one
    // application container (and its session-guard singleton) across
    // sequential calls within a single test method, so a `postJson`
    // login followed by a `get` in the same test authenticates
    // regardless of headers — it isn't exercising two independent
    // requests against two independent processes the way a real
    // browser and a real Docker stack are. Confirmed directly: with the
    // buggy middleware deliberately restored, every test below still
    // passed. So the regression itself is pinned here, directly and
    // deterministically, by asserting the actual middleware the route
    // is registered with — this fails immediately if anyone reverts to
    // 'auth:sanctum' alone, independent of any test-harness limitation.
    public function test_the_verification_route_uses_the_unconditional_web_session_guard_not_bare_sanctum(): void
    {
        $route = Route::getRoutes()->getByName('verification.verify');
        $this->assertNotNull($route, 'verification.verify route is not registered.');

        $middleware = $route->middleware();

        $this->assertContains('web', $middleware, "Must include the 'web' group — it starts the session unconditionally, unlike Sanctum's Referer/Origin-gated bootstrap.");
        $this->assertContains('auth', $middleware);
        $this->assertContains('signed', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware, "Must not fall back to bare 'auth:sanctum' — see docs/EMAIL_VERIFICATION.md for why that breaks every real email-link click.");
    }

    // ---- Functional coverage via a real session cookie -------------
    //
    // Not a substitute for the test above (see the test-harness caveat
    // there) — these confirm the route still behaves correctly
    // end-to-end through a real login + real session cookie, without
    // actingAs() shortcutting straight past the guard.

    public function test_a_logged_in_user_can_verify_via_a_real_session_cookie_with_no_referer(): void
    {
        $user = User::factory()->unverified()->create();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['Referer' => 'http://localhost:8080/login']);
        $login->assertOk();

        $sessionCookie = $login->getCookie(config('session.cookie'));
        $this->assertNotNull($sessionCookie, 'Login did not set a session cookie.');

        // No actingAs(), and — unlike every request above — no
        // Referer/Origin at all: tests/TestCase.php's setUp() injects a
        // default Referer on every request specifically so cookie-
        // session tests exercise the real stateful path (see its own
        // comment), so it has to be explicitly removed here to get the
        // one case that default can't represent: a genuine top-level
        // navigation from an email client, which sends no Referer.
        // Only the session cookie carries the identity.
        $response = $this->withoutHeader('Referer')
            ->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->get($this->signedVerificationUrl($user));

        $response->assertRedirect();
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_logged_in_user_can_verify_when_the_referer_is_a_third_party_origin(): void
    {
        $user = User::factory()->unverified()->create();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['Referer' => 'http://localhost:8080/login']);
        $sessionCookie = $login->getCookie(config('session.cookie'));

        // Referer set, but to something that is NOT in
        // SANCTUM_STATEFUL_DOMAINS — e.g. a mail client's own origin or
        // a webmail preview page. This used to return "Unauthenticated"
        // even with a fully valid session, because
        // EnsureFrontendRequestsAreStateful::fromFrontend() gated
        // whether the session was even read.
        $response = $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->withHeaders(['Referer' => 'https://mail.example.com/view'])
            ->get($this->signedVerificationUrl($user));

        $response->assertRedirect();
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_guest_with_no_session_cookie_is_still_rejected_via_the_real_path(): void
    {
        $user = User::factory()->unverified()->create();

        // No login, no cookie at all — the auth requirement itself
        // must still hold on the real path, not just under actingAs().
        $response = $this->get($this->signedVerificationUrl($user));

        $response->assertUnauthorized();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_signature_is_still_rejected_even_with_a_valid_session(): void
    {
        $user = User::factory()->unverified()->create();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['Referer' => 'http://localhost:8080/login']);
        $sessionCookie = $login->getCookie(config('session.cookie'));

        $tamperedUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('someone-elses-email@example.com'),
        ]);

        $response = $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->get($tamperedUrl);

        $response->assertForbidden();
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
