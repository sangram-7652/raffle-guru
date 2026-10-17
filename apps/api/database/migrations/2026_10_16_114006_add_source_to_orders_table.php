<?php

use App\Enums\OrderSource;
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
        Schema::table('orders', function (Blueprint $table) {
            // Single source of truth for "is this revenue or a
            // giveaway" — see App\Enums\OrderSource. Every existing row
            // backfills to 'purchase' (the only kind of order that
            // existed before this column), so this is safe to add
            // without a data migration step.
            $table->string('source')->default(OrderSource::Purchase->value)->index()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
