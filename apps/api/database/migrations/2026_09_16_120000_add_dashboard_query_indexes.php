<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11.4 — indexes genuinely missing for the admin dashboard's queries,
 * confirmed via `SHOW INDEX` against the real MySQL schema before
 * writing this migration (not assumed from migration files alone):
 *
 * - `competitions.status`, `orders.status`, `payments.status`, and
 *   `competitions.(status, ends_at)` (needed for "ending soon") were
 *   ALREADY indexed (P2/P8) — nothing added for those here.
 * - `winners.status`, `winner_claims.status`, `prize_deliveries.status`
 *   had NO index at all — the dashboard's status-breakdown widgets for
 *   each would otherwise force a full table scan.
 * - `competition_lifecycle_events`, `draw_audit_logs` and
 *   `winner_audit_logs` each only had composite indexes led by a
 *   foreign key (competition_id/draw_id/winner_id), which cannot serve
 *   a global "most recent activity across everything" query — that
 *   needs `created_at` as the leading (here, only) column. The
 *   Recent Operational Activity widget queries each table with
 *   `ORDER BY created_at DESC LIMIT 10` and no other WHERE clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winners', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('winner_claims', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('prize_deliveries', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('competition_lifecycle_events', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('draw_audit_logs', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('winner_audit_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('winners', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('winner_claims', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('prize_deliveries', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('competition_lifecycle_events', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('draw_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('winner_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
