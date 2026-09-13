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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            // Denormalized from entry (order_id/user_id) — deliberately,
            // for the account-entries and order-detail "tickets for this
            // order/user" reads, which would otherwise need a join through
            // entries for every ticket row. Same pattern P5 already uses
            // (order_items.competition_title snapshotting competitions).
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Sequential per competition, starting at 1 — never the same
            // as `id` (never expose the internal PK as a ticket number).
            // See docs/TICKET_ENGINE_DESIGN_P7.md "Ticket numbering
            // strategy".
            $table->unsignedInteger('ticket_number');
            // Always equal to created_at today (no reservation phase) —
            // present because the field list for this phase asked for it
            // explicitly, and it makes "when was this ticket allocated"
            // self-describing without relying on created_at doubling as
            // that fact.
            $table->timestamp('allocated_at');
            $table->timestamps();

            // The actual capacity/correctness guarantee — see
            // docs/TICKET_ENGINE_DESIGN_P7.md. The competition row lock is
            // the primary mechanism; this is the hard safety net.
            $table->unique(['competition_id', 'ticket_number']);
            // The per-user-limit check's exact query shape.
            $table->index(['competition_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
