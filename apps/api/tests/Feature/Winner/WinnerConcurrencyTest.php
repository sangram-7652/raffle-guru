<?php

namespace Tests\Feature\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Enums\OrderStatus;
use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Prize;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Services\Winner\PrizeFulfilmentService;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use App\Services\Winner\WinnerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs against REAL MySQL via pcntl_fork()'d child processes — same
 * technique and reasoning as tests/Feature/Draw/DrawConcurrencyTest.php
 * (P9). See docs/WINNER_CLAIM_P10.md "Concurrency" for the full matrix
 * (A/B/C/D/E/F); this file exercises A, B, and E directly with real
 * forked processes — the scenarios with the widest real-world race
 * window (a retried queue job, a double-submitted form, a double-
 * clicked "create delivery" admin action). D (two verification
 * attempts) and F (two completion attempts) share the identical
 * lock-then-matrix-check mechanism proven here and in
 * WinnerLifecycleServiceTest/WinnerClaimTest — the row lock inside
 * WinnerLifecycleService::transition() is the single serialization
 * point for every status change, verification and completion included.
 */
class WinnerConcurrencyTest extends TestCase
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

        Config::set('database.connections.mysql.database', 'raffleguru');
        Config::set('database.default', 'mysql');
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        DB::connection('mysql')->table('winner_audit_logs')->whereIn('winner_id', function ($q) {
            $q->select('id')->from('winners')->whereIn('competition_id', $this->createdCompetitionIds);
        })->delete();
        DB::connection('mysql')->table('winner_claims')->whereIn('winner_id', function ($q) {
            $q->select('id')->from('winners')->whereIn('competition_id', $this->createdCompetitionIds);
        })->delete();
        DB::connection('mysql')->table('prize_deliveries')->whereIn('winner_id', function ($q) {
            $q->select('id')->from('winners')->whereIn('competition_id', $this->createdCompetitionIds);
        })->delete();
        DB::connection('mysql')->table('winners')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('draw_audit_logs')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('draw_results')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('draws')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('tickets')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('entries')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('order_items')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('prizes')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('orders')->whereIn('user_id', $this->createdUserIds)->delete();
        DB::connection('mysql')->table('competition_lifecycle_events')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('competitions')->whereIn('id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('users')->whereIn('id', $this->createdUserIds)->delete();

        Config::set('database.default', 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    /**
     * @return array{competition: Competition, drawResult: DrawResult}
     */
    private function makeDrawnCompetitionWithPrize(): array
    {
        $competition = Competition::query()->create([
            'slug' => 'winner-concurrency-'.uniqid(),
            'title' => 'Winner Concurrency Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Drawn,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => 20,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'ends_at' => now()->subMinute(),
            'draw_at' => now()->subMinute(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        Prize::query()->create([
            'competition_id' => $competition->id,
            'title' => 'Test Prize',
            'value' => 1000,
            'currency' => 'GBP',
            'quantity' => 1,
        ]);

        $user = User::query()->create([
            'name' => 'Winner Concurrency User',
            'email' => 'winner-concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);
        $this->createdUserIds[] = $user->id;

        $order = Order::query()->create([
            'user_id' => $user->id,
            'reference' => 'RG-'.random_int(100000, 999999),
            'status' => OrderStatus::Paid,
            'currency' => 'GBP',
            'subtotal' => 500,
            'total' => 500,
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'competition_id' => $competition->id,
            'prize_id' => null,
            'competition_title' => $competition->title,
            'quantity' => 5,
            'unit_price' => 100,
            'currency' => 'GBP',
            'line_total' => 500,
        ]);
        $entry = Entry::query()->create([
            'user_id' => $user->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => 5,
        ]);

        $ticket = Ticket::query()->create([
            'entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'ticket_number' => 1,
            'allocated_at' => now(),
        ]);

        $draw = Draw::query()->create([
            'competition_id' => $competition->id,
            'type' => 'automated',
            'status' => DrawStatus::Completed,
            'attempt' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'eligible_ticket_count' => 1,
            'eligibility_version' => 'v1:ticket.order.status=paid',
            'snapshot_checksum' => 'test',
            'randomness_method' => 'test',
        ]);

        $drawResult = DrawResult::query()->create([
            'draw_id' => $draw->id,
            'competition_id' => $competition->id,
            'ticket_id' => $ticket->id,
            'ticket_number' => 1,
            'eligible_ticket_count' => 1,
            'selected_at' => now(),
        ]);

        return ['competition' => $competition, 'drawResult' => $drawResult];
    }

    /**
     * Forks a child running $work($competitionId), writing its outcome
     * as JSON to $resultPath. Always exit()s.
     *
     * @param  \Closure(int):array<string, mixed>  $work
     */
    private function fork(int $subjectId, string $resultPath, \Closure $work): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            try {
                $outcome = $work($subjectId);
            } catch (\Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
            }

            file_put_contents($resultPath, json_encode($outcome));
            exit(0);
        }

        return $pid;
    }

    /**
     * Test A: two CreateWinnerJob-equivalent executions racing on the
     * same drawn competition. Expected: exactly one winner.
     */
    public function test_two_concurrent_winner_creations_produce_exactly_one_winner(): void
    {
        ['competition' => $competition] = $this->makeDrawnCompetitionWithPrize();

        $resultA = sys_get_temp_dir().'/p10_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p10_concurrency_'.uniqid().'_b.json';

        $work = function (int $competitionId): array {
            $competition = Competition::query()->findOrFail($competitionId);
            $winner = app(WinnerService::class)->createFromDrawResult($competition);

            return ['ok' => true, 'winner_id' => $winner->id];
        };

        $pidA = $this->fork($competition->id, $resultA, $work);
        $pidB = $this->fork($competition->id, $resultB, $work);
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        $this->assertTrue($outcomeA['ok'], json_encode($outcomeA));
        $this->assertTrue($outcomeB['ok'], json_encode($outcomeB));
        $this->assertSame($outcomeA['winner_id'], $outcomeB['winner_id'], 'Both attempts must resolve to the SAME winner.');

        $this->assertSame(1, DB::connection('mysql')->table('winners')->where('competition_id', $competition->id)->count());
    }

    /**
     * Test B: two simultaneous claim submissions for the same winner.
     * Expected: exactly one valid claim.
     */
    public function test_two_concurrent_claim_submissions_produce_exactly_one_claim(): void
    {
        ['competition' => $competition, 'drawResult' => $drawResult] = $this->makeDrawnCompetitionWithPrize();
        $winner = app(WinnerService::class)->createFromDrawResult($competition->fresh());
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'test setup');

        $resultA = sys_get_temp_dir().'/p10_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p10_concurrency_'.uniqid().'_b.json';

        $work = function () use ($winner): array {
            $freshWinner = Winner::query()->findOrFail($winner->id);
            $claim = app(WinnerClaimService::class)->submit($freshWinner, 'racing submission');

            return ['ok' => true, 'claim_id' => $claim->id];
        };

        $pidA = $this->fork($winner->id, $resultA, $work);
        $pidB = $this->fork($winner->id, $resultB, $work);
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // Exactly one attempt must have succeeded; the other must have
        // lost the race (WinnerClaimException), never silently created
        // a second row.
        $succeeded = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['ok']);
        $this->assertCount(1, $succeeded, 'Exactly one concurrent claim submission may succeed.');

        $this->assertSame(1, DB::connection('mysql')->table('winner_claims')->where('winner_id', $winner->id)->count());
    }

    /**
     * Test E: two simultaneous delivery-creation attempts for the same
     * winner. Expected: exactly one delivery.
     */
    public function test_two_concurrent_delivery_creations_produce_exactly_one_delivery(): void
    {
        ['competition' => $competition] = $this->makeDrawnCompetitionWithPrize();
        $winner = app(WinnerService::class)->createFromDrawResult($competition->fresh());
        $lifecycle = app(WinnerLifecycleService::class);
        $lifecycle->transition($winner, WinnerStatus::Contacted, 'test setup');
        app(WinnerClaimService::class)->submit($winner->fresh(), null);
        $claim = $winner->fresh()->claim;
        app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::VerificationPending, 'test');
        app(WinnerClaimService::class)->transition($claim->fresh(), WinnerClaimStatus::Verified, 'test');
        app(WinnerClaimService::class)->transition($claim->fresh(), WinnerClaimStatus::Approved, 'test');
        app(PrizeFulfilmentService::class)->markDeliveryPending($winner->fresh());

        $resultA = sys_get_temp_dir().'/p10_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p10_concurrency_'.uniqid().'_b.json';

        $work = function () use ($winner): array {
            $freshWinner = Winner::query()->findOrFail($winner->id);
            $delivery = app(PrizeFulfilmentService::class)->createDelivery($freshWinner);

            return ['ok' => true, 'delivery_id' => $delivery->id];
        };

        $pidA = $this->fork($winner->id, $resultA, $work);
        $pidB = $this->fork($winner->id, $resultB, $work);
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        $this->assertTrue($outcomeA['ok'], json_encode($outcomeA));
        $this->assertTrue($outcomeB['ok'], json_encode($outcomeB));
        $this->assertSame($outcomeA['delivery_id'], $outcomeB['delivery_id'], 'Both attempts must resolve to the SAME delivery.');

        $this->assertSame(1, DB::connection('mysql')->table('prize_deliveries')->where('winner_id', $winner->id)->count());
    }
}
