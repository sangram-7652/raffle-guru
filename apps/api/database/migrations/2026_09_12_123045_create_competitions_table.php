<?php

use App\Enums\CompetitionStatus;
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
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('short_description');
            // Paragraphs, as the frontend already renders them — a content
            // blob, not a normalized child table (see docs/SCHEMA_DESIGN_P2.md).
            $table->json('description')->nullable();
            // Plain string for now, not a categories table — see
            // docs/SCHEMA_DESIGN_P2.md (deferred to P4).
            $table->string('category')->index();
            // Backed by app/Enums/CompetitionStatus — plain string column so
            // adding a new status is a code change, not an ALTER TABLE.
            $table->string('status')->default(CompetitionStatus::Draft->value)->index();
            // Minor units (pence), never float — exact money arithmetic and
            // matches how payment providers bill (P6).
            $table->unsignedInteger('entry_price');
            $table->char('currency', 3)->default('GBP');
            $table->unsignedInteger('max_entries');
            $table->unsignedInteger('max_entries_per_user')->nullable();
            $table->json('images')->nullable();
            // Skill-testing question (prompt/options/answer_index). Nullable
            // for a genuine free-entry route. NEVER expose answer_index in a
            // public API response (P4) — see docs/SCHEMA_DESIGN_P2.md.
            $table->json('skill_question')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('draw_at')->nullable()->index();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            // Orders/tickets/draws (P5/P7/P9) will reference competitions by
            // ID — soft delete so history stays intact.
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
