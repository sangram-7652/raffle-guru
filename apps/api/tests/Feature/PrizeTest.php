<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Prize;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_prize_can_be_persisted(): void
    {
        $prize = Prize::factory()->create(['title' => 'BMW M4 Competition xDrive']);

        $this->assertDatabaseHas('prizes', [
            'id' => $prize->id,
            'title' => 'BMW M4 Competition xDrive',
        ]);
    }

    public function test_prize_belongs_to_a_competition(): void
    {
        $competition = Competition::factory()->create();
        $prize = Prize::factory()->for($competition)->create();

        $this->assertTrue($prize->competition->is($competition));
    }

    public function test_competition_has_many_prizes(): void
    {
        $competition = Competition::factory()->create();
        Prize::factory()->for($competition)->count(2)->create();

        $this->assertCount(2, $competition->prizes);
    }

    public function test_monetary_fields_preserve_exact_integer_values(): void
    {
        $prize = Prize::factory()->create(['value' => 8_400_000, 'cash_alternative_value' => 5_800_000]);

        $fresh = $prize->fresh();
        $this->assertSame(8_400_000, $fresh->value);
        $this->assertSame(5_800_000, $fresh->cash_alternative_value);
    }

    public function test_deleting_a_competition_with_prizes_is_restricted(): void
    {
        $competition = Competition::factory()->create();
        Prize::factory()->for($competition)->create();

        $this->expectException(QueryException::class);

        // Soft delete doesn't touch the FK, so force a real hard delete to
        // prove the restrictOnDelete constraint actually holds.
        $competition->forceDelete();
    }
}
