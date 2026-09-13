<?php

namespace Tests\Feature;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_competition_can_be_persisted(): void
    {
        $competition = Competition::factory()->create(['title' => 'BMW M4 Competition']);

        $this->assertDatabaseHas('competitions', [
            'id' => $competition->id,
            'title' => 'BMW M4 Competition',
        ]);
    }

    public function test_slug_must_be_unique(): void
    {
        Competition::factory()->create(['slug' => 'bmw-m4']);

        $this->expectException(QueryException::class);

        Competition::factory()->create(['slug' => 'bmw-m4']);
    }

    public function test_status_casts_to_the_backed_enum(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create();

        $this->assertInstanceOf(CompetitionStatus::class, $competition->fresh()->status);
        $this->assertSame(CompetitionStatus::SoldOut, $competition->fresh()->status);
    }

    public function test_monetary_fields_preserve_exact_integer_values(): void
    {
        $competition = Competition::factory()->create(['entry_price' => 99]);

        $this->assertSame(99, $competition->fresh()->entry_price);
        $this->assertIsInt($competition->fresh()->entry_price);
    }

    public function test_max_entries_fields_are_persisted_and_nullable_per_user_limit(): void
    {
        $competition = Competition::factory()->create([
            'max_entries' => 95_000,
            'max_entries_per_user' => null,
        ]);

        $fresh = $competition->fresh();
        $this->assertSame(95_000, $fresh->max_entries);
        $this->assertNull($fresh->max_entries_per_user);
    }

    public function test_soft_deleted_competition_is_excluded_from_default_queries(): void
    {
        $competition = Competition::factory()->create();

        $competition->delete();

        $this->assertDatabaseHas('competitions', ['id' => $competition->id]);
        $this->assertNull(Competition::find($competition->id));
    }
}
