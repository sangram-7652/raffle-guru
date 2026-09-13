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
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            // Financial/audit record — restrictOnDelete, same reasoning as
            // orders/payments (P5/P6): never let an entry disappear out
            // from under its order/user/competition.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // One entry per order item, ever — the idempotency guarantee
            // for allocation (see docs/TICKET_ENGINE_DESIGN_P7.md).
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // "How many has this user got in this competition" — the
            // per-user-limit check's exact query shape.
            $table->index(['competition_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
