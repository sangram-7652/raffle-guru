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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            // unique, not just indexed: one cart per user, enforced by the
            // database — see docs/SCHEMA_DESIGN_P5.md. No guest carts.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('GBP');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
