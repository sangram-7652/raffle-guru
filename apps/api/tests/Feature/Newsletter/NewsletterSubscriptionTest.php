<?php

namespace Tests\Feature\Newsletter;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_email_can_subscribe(): void
    {
        $response = $this->postJson('/api/v1/newsletter/subscribe', [
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['message']]);
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'jane@example.com',
            'unsubscribed_at' => null,
        ]);
    }

    public function test_email_is_normalised_to_lowercase(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'Jane@Example.COM']);

        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'jane@example.com']);
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'not-an-email']);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_missing_email_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/newsletter/subscribe', []);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_duplicate_submission_is_idempotent_not_an_error(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'jane@example.com'])
            ->assertOk();

        $response = $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'jane@example.com']);

        $response->assertOk();
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_resubscribing_after_unsubscribe_clears_unsubscribed_at(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'jane@example.com',
            'unsubscribe_token' => NewsletterSubscriber::generateUnsubscribeToken(),
            'subscribed_at' => now()->subMonth(),
            'unsubscribed_at' => now()->subWeek(),
        ]);

        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'jane@example.com'])
            ->assertOk();

        $this->assertNull($subscriber->refresh()->unsubscribed_at);
    }

    public function test_response_never_exposes_the_submitted_email(): void
    {
        $response = $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'jane@example.com']);

        $response->assertJsonMissingPath('data.email');
        $response->assertDontSee('jane@example.com', false);
    }

    public function test_repeated_submissions_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/newsletter/subscribe', ['email' => "user{$i}@example.com"]);
        }

        $response = $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'onemore@example.com']);

        $response->assertTooManyRequests();
    }

    public function test_valid_token_unsubscribes_the_address(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'jane@example.com',
            'unsubscribe_token' => 'a-valid-token',
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
        ]);

        $response = $this->getJson('/api/v1/newsletter/unsubscribe/a-valid-token');

        $response->assertOk();
        $this->assertNotNull($subscriber->refresh()->unsubscribed_at);
    }

    public function test_unsubscribing_twice_is_a_harmless_no_op(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'jane@example.com',
            'unsubscribe_token' => 'a-valid-token',
            'subscribed_at' => now(),
            'unsubscribed_at' => now()->subDay(),
        ]);
        $firstUnsubscribedAt = $subscriber->unsubscribed_at;

        $this->getJson('/api/v1/newsletter/unsubscribe/a-valid-token')->assertOk();

        $this->assertEquals($firstUnsubscribedAt, $subscriber->refresh()->unsubscribed_at);
    }

    public function test_invalid_token_returns_404_not_a_server_error(): void
    {
        $response = $this->getJson('/api/v1/newsletter/unsubscribe/not-a-real-token');

        $response->assertNotFound();
    }

    public function test_no_ticket_or_entry_is_ever_created_by_subscribing(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'jane@example.com'])
            ->assertOk();

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('entries', 0);
    }
}
