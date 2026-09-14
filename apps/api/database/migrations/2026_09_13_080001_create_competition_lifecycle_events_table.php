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
        Schema::create('competition_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            // Plain string snapshots of the enum values either side of
            // the transition — not a foreign key to anything, just an
            // immutable record of what happened. See
            // docs/COMPETITION_LIFECYCLE_P8.md "Audit trail".
            $table->string('from_status');
            $table->string('to_status');
            $table->string('reason');
            // created_at only — an event log entry is never edited.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['competition_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_lifecycle_events');
    }
};
