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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, consistent with prizes->competitions (P2):
            // a competition sitting in someone's cart shouldn't be
            // hard-deletable out from under them.
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // Snapshot at add-to-cart time — never trust a client-supplied
            // price. Re-resolved fresh again at order-creation time (see
            // OrderController) since this can go stale between adding to
            // cart and checking out.
            $table->unsignedInteger('unit_price');
            $table->char('currency', 3)->default('GBP');
            $table->timestamps();

            // Adding the same competition again increments quantity on the
            // existing line rather than creating a duplicate row.
            $table->unique(['cart_id', 'competition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
