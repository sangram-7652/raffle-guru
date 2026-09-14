<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Services\Draw\DrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;

/**
 * RNG-specific tests — test matrix items 13-17. Deliberately goes
 * beyond "winner != null": these assert the actual selection
 * properties (source, eligibility, server-control, scale) called out in
 * the phase brief's "RNG testing" section.
 */
class DrawRandomnessTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    /**
     * #13/#14/#9 — scans every file in the draw domain (plus the job
     * that invokes it) for the banned function names. A distribution
     * test alone could pass by coincidence even with a bad
     * implementation swapped in; this is the actual guarantee.
     */
    public function test_no_mt_rand_or_rand_is_used_anywhere_in_the_draw_domain(): void
    {
        $files = array_merge(
            glob(base_path('app/Services/Draw/*.php')),
            glob(base_path('app/Services/Draw/Strategies/*.php')),
            [base_path('app/Jobs/DrawCompetitionJob.php')],
        );

        $this->assertNotEmpty($files, 'Expected to find draw-domain source files to scan.');

        foreach ($files as $file) {
            $source = file_get_contents($file);
            // Word-boundary match so e.g. "random_int" itself doesn't
            // false-positive against a bare "rand" substring check.
            $this->assertDoesNotMatchRegularExpression(
                '/\b(mt_rand|rand|uniqid|microtime)\s*\(/',
                $source,
                basename($file).' must not use a non-cryptographic randomness source.',
            );
        }
    }

    public function test_random_int_is_the_randomness_source_used(): void
    {
        $source = file_get_contents(base_path('app/Services/Draw/DrawRandomnessService.php'));

        $this->assertStringContainsString('random_int(', $source);
    }

    /**
     * #15 — across many independent draws, the winner is always a
     * ticket that was genuinely eligible for THAT competition, never a
     * refunded one and never a different competition's ticket.
     */
    public function test_selected_ticket_is_always_eligible_across_many_draws(): void
    {
        for ($trial = 0; $trial < 15; $trial++) {
            ['competition' => $competition, 'eligibleTicketNumbers' => $eligible, 'ineligibleTicketNumbers' => $ineligible] =
                $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, eligibleCount: 6, refundedCount: 3);

            $draw = app(DrawService::class)->run($competition);

            $this->assertNotNull($draw);
            $this->assertContains($draw->result->ticket_number, $eligible, "Trial {$trial}: winner must be one of the eligible numbers.");
            $this->assertNotContains($draw->result->ticket_number, $ineligible, "Trial {$trial}: winner must never be a refunded ticket.");
            $this->assertSame($competition->id, $draw->result->competition_id, "Trial {$trial}: winner must belong to the drawn competition.");
        }
    }

    /**
     * #6 (distribution sanity, documented) — over many trials against
     * the SAME fixed eligible set, every eligible ticket should be
     * pickable (no ticket structurally unreachable, e.g. an off-by-one
     * excluding the last number) and no single ticket should dominate.
     * This is a coarse sanity check, not a rigorous statistical test —
     * with 8 tickets and 200 trials under a true uniform distribution,
     * seeing zero occurrences of any one ticket, or one ticket taking
     * an overwhelming majority, would indicate a real bias; anything
     * else is expected sampling noise and is not asserted more tightly
     * than that, to avoid a flaky test.
     */
    public function test_selection_distribution_does_not_show_gross_bias(): void
    {
        ['competition' => $competition, 'eligibleTicketNumbers' => $eligible] =
            $this->createCompetitionWithTickets(CompetitionStatus::Closed, eligibleCount: 8);

        $counts = array_fill_keys($eligible, 0);
        $trials = 200;

        for ($i = 0; $i < $trials; $i++) {
            $ticket = app(\App\Services\Draw\DrawRandomnessService::class)->selectEligibleTicket(
                $competition,
                app(\App\Services\Draw\DrawEligibilityService::class)->snapshot($competition),
            );
            $counts[$ticket->ticket_number]++;
        }

        foreach ($eligible as $number) {
            $this->assertGreaterThan(0, $counts[$number], "Ticket #{$number} was never selected across {$trials} trials — possible bias.");
        }

        $maxShare = max($counts) / $trials;
        $this->assertLessThan(0.6, $maxShare, 'One ticket dominated selection far beyond chance — possible bias.');
    }

    /**
     * #16 — nothing in the strategy's selection signature accepts an
     * externally-suppliable ticket/number; the only inputs are the
     * server-loaded Competition and Draw models. Combined with there
     * being no HTTP route into the draw engine at all (see
     * DrawApiTest), this is what "server-controlled" means concretely.
     */
    public function test_strategy_selection_accepts_no_client_suppliable_input(): void
    {
        $method = new ReflectionMethod(\App\Services\Draw\Strategies\AutomatedDrawStrategy::class, 'select');
        $paramTypes = array_map(
            fn ($p) => (string) $p->getType(),
            $method->getParameters(),
        );

        $this->assertSame(['App\\Models\\Competition', 'App\\Models\\Draw'], $paramTypes);
    }

    /**
     * #17 / large-dataset — a competition with several thousand
     * tickets. Bounds actual behavior, not just "it completes": no
     * query in the trace returns more than a small, constant number of
     * rows for the tickets table, proving the implementation never
     * materializes the ticket set (a `Ticket::all()`-style regression
     * would show up here as a query returning thousands of rows).
     * Kept at a scale (5,000) that stays fast enough for routine CI —
     * the query-shape assertion, not row count, is what actually catches
     * a memory-blowup regression at any real production scale (see
     * docs/DRAW_ENGINE_P9.md "Performance notes" for the argument that
     * the same query shapes hold at 1,000,000+).
     */
    public function test_large_ticket_set_never_loads_more_than_a_handful_of_rows(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, eligibleCount: 5000);

        DB::enableQueryLog();
        $draw = app(DrawService::class)->run($competition);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotNull($draw);
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);

        // Every single query issued during the whole run() call must be
        // cheap to return to PHP — an aggregate row, a max() scalar, or
        // at most a handful of small model rows (competition/draw/order
        // lookups) — never a bulk ticket fetch.
        foreach ($log as $entry) {
            $sql = strtolower($entry['query']);
            if (str_contains($sql, 'select') && str_contains($sql, 'from `tickets`') && ! str_contains($sql, 'count(') && ! str_contains($sql, 'max(')) {
                // A non-aggregate tickets query: must be a single-row
                // point/offset lookup (LIMIT 1), never an unbounded scan.
                $this->assertStringContainsString('limit 1', $sql, 'A non-aggregate tickets query must be limited to exactly one row: '.$entry['query']);
            }
        }
    }
}
