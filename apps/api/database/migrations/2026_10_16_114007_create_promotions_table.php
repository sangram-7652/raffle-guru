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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Public-facing lookup key — the frontend requests
            // GET /v1/promotions/{slug} with this, never the id.
            $table->string('slug')->unique();
            // Exactly one eligible competition per promotion — chosen by
            // an admin at create time (see docs/EMAIL_CAPTURE.md "Which
            // competition does the free ticket enter?"). restrictOnDelete:
            // never let a competition disappear out from under a
            // promotion's claim history.
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            // Nothing is claimable until an admin explicitly turns this
            // on — never defaults to live.
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // No "unlimited" option — an admin must pick a real number.
            $table->unsignedInteger('total_capacity');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
