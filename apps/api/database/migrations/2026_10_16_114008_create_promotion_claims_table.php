<?php

use App\Enums\PromotionClaimStatus;
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
        Schema::create('promotion_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Set once the claim succeeds — upserted into
            // newsletter_subscribers at the same time (claiming implies
            // subscribing, see docs/EMAIL_CAPTURE.md). nullOnDelete: a
            // subscriber row disappearing must never block/alter a
            // claim's own history.
            $table->foreignId('newsletter_subscriber_id')->nullable()->constrained()->nullOnDelete();
            // Set once the $0 promotional Order exists — null while a
            // claim is mid-transaction or after a failed attempt that
            // never got far enough to create one.
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            // Denormalized for admin/reporting, same reasoning as
            // tickets.order_id/user_id (P7) — "which entry did this
            // claim produce" without a join through orders.
            $table->foreignId('entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default(PromotionClaimStatus::Pending->value);
            // Admin-facing only (e.g. "exhausted", "competition closed")
            // — never a raw exception message/stack trace.
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // The hard safety net (mirrors tickets.unique(competition_id,
            // ticket_number), P7): at most one claim row per person per
            // promotion, ever — this is what makes "duplicate/concurrent
            // requests never award a second ticket" true regardless of
            // the row-lock strategy above it.
            $table->unique(['promotion_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_claims');
    }
};
