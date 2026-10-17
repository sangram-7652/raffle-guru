<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['email', 'unsubscribe_token', 'subscribed_at', 'unsubscribed_at'])]
class NewsletterSubscriber extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Opaque, unguessable token for the public unsubscribe link.
     */
    public static function generateUnsubscribeToken(): string
    {
        return Str::random(48);
    }

    public function isUnsubscribed(): bool
    {
        return $this->unsubscribed_at !== null;
    }

    /**
     * The one place an email becomes (or re-becomes) subscribed —
     * idempotent create-or-resubscribe, normalizing the address the
     * same way everywhere it happens. Used by both the public
     * subscribe endpoint and PromotionClaimService (claiming a free
     * ticket implies subscribing — see docs/EMAIL_CAPTURE.md).
     */
    public static function subscribe(string $email): self
    {
        $email = mb_strtolower(trim($email));

        $subscriber = static::query()->where('email', $email)->first();

        if ($subscriber === null) {
            return static::create([
                'email' => $email,
                'unsubscribe_token' => static::generateUnsubscribeToken(),
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ]);
        }

        $subscriber->update([
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
        ]);

        return $subscriber;
    }
}
