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
        Schema::create('winners', function (Blueprint $table) {
            $table->id();

            // Three independent UNIQUE constraints, each the hard
            // database-level guarantee behind "exactly one winner per
            // competition/draw/draw_result" — same "constraint as
            // defense-in-depth" pattern as draw_results.competition_id
            // (P9). Any one of these alone is sufficient; all three are
            // kept because each names a different real-world invariant
            // and costs nothing extra to enforce.
            $table->foreignId('competition_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('draw_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('draw_result_id')->unique()->constrained()->restrictOnDelete();

            // The winning ticket and its owner — resolved once, at
            // creation, from draw_results.ticket_id / tickets.user_id.
            // Never written from any other source (see
            // App\Services\Winner\WinnerService). restrictOnDelete: a
            // ticket/user must never be deletable once it has won.
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // App\Enums\WinnerStatus — plain string column, not MySQL
            // ENUM (same P2 convention as every other status column in
            // this schema).
            $table->string('status')->default('pending_contact');

            // One timestamp per forward-only lifecycle step it can ever
            // reach — see docs/WINNER_CLAIM_P10.md "Winner lifecycle".
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('claim_approved_at')->nullable();
            $table->timestamp('delivery_pending_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Non-PII structured context only (e.g. reconciliation
            // notes) — never contact details, KYC data, or secrets. See
            // docs/WINNER_CLAIM_P10.md "Privacy".
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winners');
    }
};
