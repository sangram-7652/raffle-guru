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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            // Line items have no independent meaning without their order —
            // cascading here is correct (unlike orders->users, which
            // restricts). The order row itself is still soft-deleted.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            // Informational only — never blocks a prize being changed/
            // removed later. See docs/SCHEMA_DESIGN_P5.md re: why no
            // separate prize title/value snapshot was added.
            $table->foreignId('prize_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot — stays meaningful even if the competition's title
            // changes or it's later soft-deleted.
            $table->string('competition_title');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->char('currency', 3)->default('GBP');
            $table->unsignedInteger('line_total');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
