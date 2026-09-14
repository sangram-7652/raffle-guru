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
        Schema::create('draws', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete — same reasoning as every other
            // financial/audit record in this schema (P2/P5/P6/P7/P8):
            // a draw attempt must never disappear out from under its
            // competition.
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();

            // Which strategy ran this attempt — App\Enums\DrawType.
            // Plain string column, not MySQL ENUM (P2 convention).
            $table->string('type');

            // Execution status of THIS attempt — App\Enums\DrawStatus.
            // Independent of competitions.status (see the enum's own
            // docblock and docs/DRAW_ENGINE_P9.md "Status model").
            $table->string('status');

            // 1, 2, 3... per competition — lets crash-recovery create a
            // fresh attempt after a stale one without losing the
            // ordering/history. See docs/DRAW_ENGINE_P9.md "Crash
            // recovery".
            $table->unsignedInteger('attempt');

            // Eligibility snapshot metadata (P9 "Draw snapshot")
            // — deliberately NOT a copy of every eligible ticket row;
            // see docs/DRAW_ENGINE_P9.md "Snapshot strategy" for why
            // count/range/checksum is enough to audit without
            // duplicating a potentially huge dataset.
            $table->timestamp('snapshot_at')->nullable();
            $table->unsignedInteger('eligible_ticket_count')->nullable();
            $table->string('eligibility_version')->nullable();
            $table->string('snapshot_checksum')->nullable();

            // How the winning ticket was chosen — recorded for audit,
            // never a secret. See docs/DRAW_ENGINE_P9.md "RNG choice".
            $table->string('randomness_method')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // The command/scheduler's exact query shape: "find the
            // running attempt(s) for this competition, newest first".
            $table->index(['competition_id', 'status', 'attempt']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draws');
    }
};
