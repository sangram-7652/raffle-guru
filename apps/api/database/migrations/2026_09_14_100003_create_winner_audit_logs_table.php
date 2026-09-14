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
        Schema::create('winner_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('winner_id')->constrained()->restrictOnDelete();

            // e.g. winner_created, winner_contacted, claim_submitted,
            // verification_started, winner_verified, claim_approved,
            // delivery_created, prize_shipped, prize_delivered,
            // winner_completed — see docs/WINNER_CLAIM_P10.md "Audit".
            $table->string('event');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();

            // 'system' (automatic, e.g. winner_created from the draw
            // handoff, or a Winner status change that is the automatic
            // consequence of another event), 'operator' (a future
            // admin/domain-service call), or 'customer' (only ever for
            // the customer's own claim_submitted event — the one
            // customer-initiated write anywhere in this domain). No
            // winner-status DECISION (contacted/verified/approved/
            // delivered/completed) is ever written with actor_type =
            // 'customer'. See docs/WINNER_CLAIM_P10.md "Authorization".
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();

            // Structured, non-secret context only — no passwords,
            // tokens, payment credentials, or KYC secrets. See
            // docs/WINNER_CLAIM_P10.md "Privacy".
            $table->json('metadata')->nullable();

            $table->timestamp('created_at');
            // No updated_at — append-only, same convention as
            // draw_audit_logs/competition_lifecycle_events.

            $table->index(['winner_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winner_audit_logs');
    }
};
