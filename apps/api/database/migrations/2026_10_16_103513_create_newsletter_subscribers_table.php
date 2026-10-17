<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();

            // Opaque lookup key for the public, unauthenticated unsubscribe
            // link — never the row id/email itself, so the link can't be
            // used to enumerate or target other subscribers.
            $table->string('unsubscribe_token', 64)->unique();

            // Consent timestamp (re-subscribing updates this). Marketing
            // emails are not actually sent yet (no Mailable/ESP wired up —
            // see docs), but the consent record exists from day one.
            $table->timestamp('subscribed_at');
            $table->timestamp('unsubscribed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
