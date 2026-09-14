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
        Schema::create('prize_deliveries', function (Blueprint $table) {
            $table->id();

            // UNIQUE — one delivery per winner. Because P9/P10 only ever
            // produce one winner per competition (see winners table),
            // "one delivery per winner" and "one delivery per
            // competition" are the same invariant; scoping the
            // constraint to winner_id is what actually matches how this
            // table is written (see App\Services\Winner\PrizeFulfilmentService).
            $table->foreignId('winner_id')->unique()->constrained()->restrictOnDelete();

            // Nullable — see docs/WINNER_CLAIM_P10.md "Prize resolution"
            // for the documented schema limitation this reflects: a
            // competition with more than one `prizes` row has no
            // existing signal for which prize the single draw/winner
            // corresponds to, so this column is left unresolved
            // (null) rather than guessed. restrictOnDelete once set.
            $table->foreignId('prize_id')->nullable()->constrained()->restrictOnDelete();

            // App\Enums\PrizeDeliveryStatus.
            $table->string('status')->default('pending');

            // Manual fulfilment only in this phase — see
            // docs/WINNER_CLAIM_P10.md "No fake fulfilment". No courier
            // API integration; these are operator-entered facts.
            $table->string('delivery_method')->nullable();
            $table->string('tracking_reference')->nullable();

            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Operator-only context — never returned by a customer-facing
            // API resource. See docs/WINNER_CLAIM_P10.md "Privacy".
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
        Schema::dropIfExists('prize_deliveries');
    }
};
