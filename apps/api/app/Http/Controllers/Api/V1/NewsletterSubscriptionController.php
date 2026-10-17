<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Newsletter\SubscribeRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;

class NewsletterSubscriptionController extends Controller
{
    /**
     * Public, unauthenticated signup. Always responds with the same
     * generic success message whether the address is new, already
     * subscribed, or re-subscribing after a previous unsubscribe — this
     * never reveals (via response or status code) whether an email was
     * already on the list, and the email address itself is never echoed
     * back. See docs/EMAIL_CAPTURE.md for why no "free ticket" is granted
     * here.
     */
    public function store(SubscribeRequest $request): JsonResponse
    {
        NewsletterSubscriber::subscribe($request->validated('email'));

        return response()->json([
            'data' => [
                'message' => "You're on the list — we'll email you when new competitions launch.",
            ],
        ]);
    }

    /**
     * Public, token-gated one-click unsubscribe (the link a marketing
     * email would carry). Idempotent — unsubscribing twice is a no-op,
     * not an error.
     */
    public function unsubscribe(string $token): JsonResponse
    {
        $subscriber = NewsletterSubscriber::where('unsubscribe_token', $token)->first();

        if ($subscriber === null) {
            return response()->json([
                'message' => 'This unsubscribe link is invalid or has expired.',
            ], 404);
        }

        if ($subscriber->unsubscribed_at === null) {
            $subscriber->update(['unsubscribed_at' => now()]);
        }

        return response()->json([
            'data' => [
                'message' => "You've been unsubscribed and won't receive further emails.",
            ],
        ]);
    }
}
