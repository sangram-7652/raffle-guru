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
        Schema::create('winner_claims', function (Blueprint $table) {
            $table->id();

            // UNIQUE — the database-level guarantee behind "exactly one
            // active/current claim per winner" (see
            // docs/WINNER_CLAIM_P10.md "Claim concurrency"). This phase's
            // claim state machine has no resubmission path (a rejected
            // claim is a dead end here — documented limitation), so one
            // row per winner, ever, is also the correct steady-state
            // shape, not just a concurrency guard.
            $table->foreignId('winner_id')->unique()->constrained()->restrictOnDelete();

            // App\Enums\WinnerClaimStatus.
            $table->string('status');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();

            // Customer-authored free text submitted with the claim —
            // never populated from anything except the customer's own
            // request.
            $table->text('notes')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winner_claims');
    }
};
