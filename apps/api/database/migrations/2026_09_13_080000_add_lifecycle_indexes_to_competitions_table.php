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
        Schema::table('competitions', function (Blueprint $table) {
            // Match the competition:lifecycle command's three query
            // shapes exactly (P8) — see docs/COMPETITION_LIFECYCLE_P8.md.
            // `status` and `draw_at` already each have their own
            // single-column index (P2); these composites are for the
            // combined WHERE clauses the lifecycle sweep actually runs,
            // not speculative.
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
            $table->index(['status', 'draw_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropIndex(['status', 'starts_at']);
            $table->dropIndex(['status', 'ends_at']);
            $table->dropIndex(['status', 'draw_at']);
        });
    }
};
