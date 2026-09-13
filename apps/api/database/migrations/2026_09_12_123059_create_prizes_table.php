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
        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            // hasMany, not hasOne, from Competition — see
            // docs/SCHEMA_DESIGN_P2.md. constrained() also adds the index.
            // restrictOnDelete (MySQL's default, made explicit): a hard
            // delete of a competition that still has prizes fails loudly
            // instead of silently cascading prize data away.
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            $table->string('title');
            // Bullet points, as the frontend already renders them — a
            // content blob, not a normalized child table.
            $table->json('description')->nullable();
            $table->unsignedInteger('value');
            $table->char('currency', 3)->default('GBP');
            $table->unsignedInteger('cash_alternative_value')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prizes');
    }
};
