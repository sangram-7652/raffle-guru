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
        Schema::create('draw_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            // Denormalized from draw (same pattern as tickets
            // denormalizing competition_id/user_id from entry, P7) —
            // lets "every audit event for this competition" be queried
            // without joining through draws.
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            // e.g. draw.started, draw.snapshot_created, draw.completed,
            // draw.failed, draw.stale_attempt_recovered. See
            // docs/DRAW_ENGINE_P9.md "Audit model".
            $table->string('event');
            // Small, structured, non-secret context (ticket_number,
            // eligible_ticket_count, checksum, failure_reason, previous
            // attempt id, etc.) — never a stack trace, never a secret.
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['draw_id', 'created_at']);
            $table->index(['competition_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draw_audit_logs');
    }
};
