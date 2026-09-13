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
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            // sha256 of the raw webhook body — see docs/PAYMENTS_DESIGN_P6.md
            // "Idempotency strategy" for why this (not an assumed gateway
            // event id) is the deterministic dedupe key.
            $table->string('event_id', 64);
            $table->string('event_type');
            // Curated, non-sensitive subset only — never the full raw
            // payload. See docs/PAYMENTS_DESIGN_P6.md "Webhook flow".
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The actual idempotency guarantee — insert is the atomic
            // "claim this event" operation; a violation means this exact
            // delivery was already handled.
            $table->unique(['gateway', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
