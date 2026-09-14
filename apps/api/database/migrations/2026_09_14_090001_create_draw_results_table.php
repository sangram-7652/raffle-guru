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
        Schema::create('draw_results', function (Blueprint $table) {
            $table->id();

            // One result per attempt, ever.
            $table->foreignId('draw_id')->unique()->constrained()->restrictOnDelete();

            // The hard, database-level guarantee that a competition can
            // never have two winners, regardless of any application
            // bug: this unique constraint is the real backstop (same
            // "constraint as defense-in-depth" pattern as
            // tickets.unique(competition_id, ticket_number) in P7).
            $table->foreignId('competition_id')->unique()->constrained()->restrictOnDelete();

            // The winning ticket. restrictOnDelete — a ticket must never
            // be deletable once it has won.
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();

            // Denormalized from the ticket for cheap, join-free reads
            // (same reasoning as tickets.order_id/user_id in P7) and to
            // preserve the exact value shown to the public even if
            // something about the ticket relation ever changed.
            $table->unsignedInteger('ticket_number');

            // Preserved historical context — the eligible pool size at
            // the moment this specific ticket won, kept here even
            // though it also lives on the parent `draws` row, because a
            // draw_result is the artifact meant to independently answer
            // "how many tickets could this have been" without a join.
            $table->unsignedInteger('eligible_ticket_count');

            $table->timestamp('selected_at');

            $table->timestamp('created_at');
            // No updated_at — a draw result is never edited after
            // creation (see the model: UPDATED_AT = null), matching
            // CompetitionLifecycleEvent's convention for immutable
            // audit-style records.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draw_results');
    }
};
