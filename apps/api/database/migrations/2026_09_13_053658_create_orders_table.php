<?php

use App\Enums\OrderStatus;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Financial record — restrictOnDelete (not cascade), same
            // reasoning as competitions/prizes in P2: preserve history.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Public-safe reference, never the auto-increment id. Format
            // matches the pre-existing P1 mock ("RG-XXXXXX") for visual
            // continuity — generated server-side, uniqueness enforced by
            // the database, not just application-level generation.
            $table->string('reference')->unique();
            // Backed by App\Enums\OrderStatus — plain string column, same
            // reasoning as competitions.status (P2): a new status is a
            // code change, not a migration.
            $table->string('status')->default(OrderStatus::Pending->value)->index();
            $table->char('currency', 3)->default('GBP');
            // Minor units (pence), never float — see docs/SCHEMA_DESIGN_P2.md.
            // subtotal and total are separate columns even though nothing
            // in P5 makes them differ (no discount/tax logic exists yet) —
            // see docs/SCHEMA_DESIGN_P5.md.
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('total');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
