<?php

namespace Database\Seeders;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Prize;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Development-only sample data — no production credentials, no known
     * admin password. Small, realistic set: a handful of users and
     * competitions each with one prize, matching current product reality.
     */
    public function run(): void
    {
        User::factory(5)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Competition::factory()
            ->count(3)
            ->featured()
            ->withStatus(CompetitionStatus::Active)
            ->create()
            ->each(fn (Competition $competition) => Prize::factory()
                ->for($competition)
                ->withCashAlternative()
                ->create());

        Competition::factory()
            ->count(5)
            ->withStatus(CompetitionStatus::Active)
            ->create()
            ->each(fn (Competition $competition) => Prize::factory()
                ->for($competition)
                ->create());
    }
}
