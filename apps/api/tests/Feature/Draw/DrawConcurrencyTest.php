<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Competitions\CompetitionLifecycleService;
use App\Services\Draw\DrawService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Runs against REAL MySQL via pcntl_fork()'d child processes — same
 * technique and reasoning as tests/Feature/Ticket/ConcurrencyTest.php
 * (P7) and tests/Feature/Competition/LifecycleConcurrencyTest.php (P8):
 * a genuinely concurrent race cannot be exercised within one
 * single-threaded PHP process, and sequential calls prove nothing
 * about the row-lock strategy. Test matrix items 27-29.
 *
 * #[Group('mysql')] (P11.13): see AdminManagementConcurrencyTest.
 */
#[Group('mysql')]
class DrawConcurrencyTest extends TestCase
{
    /** @var array<int, int> */
    private array $createdCompetitionIds = [];

    /** @var array<int, int> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available — cannot fork for a genuine concurrency test.');
        }

        $this->useIsolatedMysqlDatabase();
    }

    protected function tearDown(): void
    {
        // A completed draw fires CompetitionDrawn synchronously under the
        // test env's QUEUE_CONNECTION=sync, so CreateWinnerJob has already
        // created a `winners` row (plus its audit log) by the time this
        // runs. winners.draw_result_id, winner_audit_logs.winner_id,
        // winner_claims.winner_id, and prize_deliveries.winner_id are all
        // restrictOnDelete(), so every dependent row must be cleaned up
        // before draw_results/winners or this delete fails with a foreign
        // key violation (P11.13 fix). winner_claims/prize_deliveries are
        // never populated within this test's own scope (no claim or
        // fulfilment action runs here) but are included for the same
        // reason winner_audit_logs was missed: silence here, not an
        // assertion failure, is how this bug stayed invisible.
        $winnerIds = DB::connection('mysql')->table('winners')->whereIn('competition_id', $this->createdCompetitionIds)->pluck('id');
        DB::connection('mysql')->table('winner_claims')->whereIn('winner_id', $winnerIds)->delete();
        DB::connection('mysql')->table('prize_deliveries')->whereIn('winner_id', $winnerIds)->delete();
        DB::connection('mysql')->table('winner_audit_logs')->whereIn('winner_id', $winnerIds)->delete();
        DB::connection('mysql')->table('winners')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('draw_audit_logs')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('draw_results')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('draws')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('tickets')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('entries')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('order_items')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('orders')->whereIn('user_id', $this->createdUserIds)->delete();
        DB::connection('mysql')->table('competition_lifecycle_events')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('competitions')->whereIn('id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('users')->whereIn('id', $this->createdUserIds)->delete();

        Config::set('database.default', 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    /**
     * @return array{0: Competition, 1: list<int>} the competition and its eligible ticket numbers
     */
    private function makeDrawPendingCompetition(int $ticketCount): array
    {
        $competition = Competition::query()->create([
            'slug' => 'draw-concurrency-'.uniqid(),
            'title' => 'Draw Concurrency Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::DrawPending,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => $ticketCount + 10,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'ends_at' => now()->subMinute(),
            'draw_at' => now()->subMinute(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        $user = User::query()->create([
            'name' => 'Draw Concurrency User',
            'email' => 'draw-concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);
        $this->createdUserIds[] = $user->id;

        $order = Order::query()->create([
            'user_id' => $user->id,
            'reference' => 'RG-'.random_int(100000, 999999),
            'status' => OrderStatus::Paid,
            'currency' => 'GBP',
            'subtotal' => $ticketCount * 100,
            'total' => $ticketCount * 100,
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'competition_id' => $competition->id,
            'prize_id' => null,
            'competition_title' => $competition->title,
            'quantity' => $ticketCount,
            'unit_price' => 100,
            'currency' => 'GBP',
            'line_total' => $ticketCount * 100,
        ]);
        $entry = Entry::query()->create([
            'user_id' => $user->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => $ticketCount,
        ]);

        $numbers = [];
        $rows = [];
        $now = now();
        for ($i = 1; $i <= $ticketCount; $i++) {
            $numbers[] = $i;
            $rows[] = [
                'entry_id' => $entry->id,
                'competition_id' => $competition->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'ticket_number' => $i,
                'allocated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Ticket::insert($rows);

        return [$competition, $numbers];
    }

    /**
     * Forks a child that runs DrawService::run() for the given
     * competition id, writing its outcome (drew successfully / null /
     * exception) to $resultPath as JSON. Always exit()s, never returns
     * to PHPUnit's own flow.
     */
    private function forkDraw(int $competitionId, string $resultPath): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            $outcome = ['drew' => false, 'error' => null];
            try {
                $competition = Competition::query()->findOrFail($competitionId);
                $draw = app(DrawService::class)->run($competition);
                $outcome['drew'] = $draw !== null;
            } catch (\Throwable $e) {
                $outcome['error'] = $e::class;
            }

            file_put_contents($resultPath, json_encode($outcome));
            exit(0);
        }

        return $pid;
    }

    /**
     * Test A: two workers attempt the same draw simultaneously.
     * Expected: exactly one successful draw, exactly one final result,
     * competition ends drawn, no duplicate winner, complete audit trail.
     */
    public function test_two_simultaneous_draws_on_the_same_competition_produce_exactly_one_winner(): void
    {
        [$competition, $eligibleNumbers] = $this->makeDrawPendingCompetition(20);

        $resultA = sys_get_temp_dir().'/p9_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p9_concurrency_'.uniqid().'_b.json';

        $pidA = $this->forkDraw($competition->id, $resultA);
        $pidB = $this->forkDraw($competition->id, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // Exactly one of the two concurrent attempts actually completed
        // the draw; the other found it already acquired/finalized and
        // no-opped (or, more subtly, both may have created a `draws`
        // attempt row if they interleaved around acquisition, but only
        // one may ever reach a completed result — asserted below).
        $drew = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['drew']);
        $this->assertGreaterThanOrEqual(1, count($drew), 'At least one attempt must have completed the draw.');

        $this->assertSame(CompetitionStatus::Drawn, Competition::query()->find($competition->id)->status);

        $results = DrawResult::query()->where('competition_id', $competition->id)->get();
        $this->assertCount(1, $results, 'Exactly one draw_results row must exist, never two winners.');
        $this->assertContains($results->first()->ticket_number, $eligibleNumbers);

        $completedDraws = Draw::query()->where('competition_id', $competition->id)->where('status', 'completed')->count();
        $this->assertSame(1, $completedDraws, 'Exactly one draw attempt may ever be marked completed.');

        // Complete audit trail: at least a started + completed event
        // exist, tied to the one completed attempt.
        $completedDraw = Draw::query()->where('competition_id', $competition->id)->where('status', 'completed')->sole();
        $events = DB::connection('mysql')->table('draw_audit_logs')->where('draw_id', $completedDraw->id)->pluck('event')->all();
        $this->assertContains('draw.started', $events);
        $this->assertContains('draw.completed', $events);
    }

    /**
     * Test B: draw finalization racing another finalization attempt —
     * exercised here as two full run() calls again (the realistic
     * shape of "two workers picked up the same draw_pending job"),
     * asserting one final winner only. Distinguished from Test A by
     * using a larger ticket count (exercises the snapshot/selection
     * work taking measurably longer, widening the window either
     * process could interleave inside).
     */
    public function test_draw_finalization_race_leaves_exactly_one_final_winner(): void
    {
        [$competition] = $this->makeDrawPendingCompetition(200);

        $resultA = sys_get_temp_dir().'/p9_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p9_concurrency_'.uniqid().'_b.json';

        $pidA = $this->forkDraw($competition->id, $resultA);
        $pidB = $this->forkDraw($competition->id, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        @unlink($resultA);
        @unlink($resultB);

        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(CompetitionStatus::Drawn, Competition::query()->find($competition->id)->status);
    }

    /**
     * Test C: draw execution racing lifecycle processing (P8) on the
     * same competition. Exercised as the exact scenario
     * docs/DRAW_ENGINE_P9.md's concurrency section describes: one
     * process runs the draw (draw_pending -> draw_in_progress ->
     * drawn), another concurrently attempts an unrelated/invalid
     * lifecycle transition on the same row. Expected: no illegal status
     * transition ever lands, and no duplicate draw.
     */
    public function test_draw_execution_racing_lifecycle_processing_causes_no_illegal_transition(): void
    {
        [$competition] = $this->makeDrawPendingCompetition(15);

        $drawResultPath = sys_get_temp_dir().'/p9_concurrency_'.uniqid().'_draw.json';
        $lifecycleResultPath = sys_get_temp_dir().'/p9_concurrency_'.uniqid().'_lifecycle.json';

        $pidDraw = $this->forkDraw($competition->id, $drawResultPath);

        $pidLifecycle = pcntl_fork();
        if ($pidLifecycle === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidLifecycle === 0) {
            DB::purge('mysql');
            // Attempts the one transition that would be illegal to ever
            // land while a draw is (or becomes) in flight: forcing
            // straight back to active. The matrix must reject this
            // regardless of interleaving.
            $competitionCopy = Competition::query()->findOrFail($competition->id);
            $result = app(CompetitionLifecycleService::class)->transition($competitionCopy, CompetitionStatus::Active, 'illegal attempt');
            file_put_contents($lifecycleResultPath, json_encode(['transitioned' => $result]));
            exit(0);
        }

        pcntl_waitpid($pidDraw, $statusDraw);
        pcntl_waitpid($pidLifecycle, $statusLifecycle);

        $lifecycleOutcome = json_decode(file_get_contents($lifecycleResultPath), true);
        @unlink($drawResultPath);
        @unlink($lifecycleResultPath);

        // The illegal transition must never have been applied, no
        // matter which process ran first.
        $this->assertFalse($lifecycleOutcome['transitioned']);

        $final = Competition::query()->find($competition->id)->status;
        $this->assertContains($final, [CompetitionStatus::DrawInProgress, CompetitionStatus::Drawn], 'Status must only ever reflect a legal draw-engine state, never active.');
        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->count(), 'No duplicate draw attempt should exist from this race.');
    }
}
