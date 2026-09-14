<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Competitions\CompetitionLifecycleService;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs against the REAL MySQL database via pcntl_fork()'d child
 * processes — same technique and same reasoning as
 * tests/Feature/Ticket/ConcurrencyTest.php (P7): a genuinely concurrent
 * race cannot be exercised within one single-threaded PHP
 * process/connection, and sequential calls prove nothing about the
 * row-lock strategy. See docs/COMPETITION_LIFECYCLE_P8.md
 * "Concurrency".
 *
 * Deliberately does NOT use RefreshDatabase — switches the app's
 * default connection to 'mysql' for its own duration, creates its own
 * fixtures, cleans them up explicitly in tearDown.
 */
class LifecycleConcurrencyTest extends TestCase
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
     * Forks a child process that attempts the given transition in
     * isolation, writing its outcome to $resultPath as JSON. The child
     * never returns to PHPUnit's own flow — it always exit()s.
     */
    private function forkTransition(int $competitionId, CompetitionStatus $to, string $reason, string $resultPath): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // Child: must not share the parent's socket/connection.
            DB::purge('mysql');

            $competition = Competition::query()->findOrFail($competitionId);
            $result = app(CompetitionLifecycleService::class)->transition($competition, $to, $reason);

            file_put_contents($resultPath, json_encode(['transitioned' => $result]));
            exit(0);
        }

        return $pid;
    }

    public function test_two_concurrent_activation_attempts_on_the_same_competition_transition_it_exactly_once(): void
    {
        $competition = Competition::query()->create([
            'slug' => 'lifecycle-concurrency-'.uniqid(),
            'title' => 'Lifecycle Concurrency Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Scheduled,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => 100,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'starts_at' => now()->subMinute(),
            'draw_at' => now()->addWeek(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        $resultA = sys_get_temp_dir().'/p8_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p8_concurrency_'.uniqid().'_b.json';

        $pidA = $this->forkTransition($competition->id, CompetitionStatus::Active, 'starts_at reached', $resultA);
        $pidB = $this->forkTransition($competition->id, CompetitionStatus::Active, 'starts_at reached', $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // Exactly one of the two concurrent attempts actually performed
        // the transition; the other found it already done and no-opped.
        $performed = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['transitioned']);
        $this->assertCount(1, $performed, 'Exactly one concurrent attempt should have actually transitioned the row.');

        $this->assertSame(
            CompetitionStatus::Active,
            Competition::query()->find($competition->id)->status,
        );

        // Not two audit rows for one real transition.
        $eventCount = DB::connection('mysql')->table('competition_lifecycle_events')
            ->where('competition_id', $competition->id)
            ->count();
        $this->assertSame(1, $eventCount);
    }

    /**
     * The exact race the P8 brief calls out: "Worker A checks
     * competition active... Worker B: competition reaches
     * max_entries... Worker A tries to allocate tickets" — here as a
     * lifecycle worker racing a ticket-allocation worker on the same
     * row (inverted: one tries to close via ends_at, the other tries
     * to allocate the last tickets to capacity). Both paths lock the
     * same competition row before acting, so whichever commits first
     * determines the other's outcome deterministically — there is no
     * interleaving that corrupts state or exceeds capacity.
     */
    public function test_lifecycle_closing_and_ticket_allocation_reaching_capacity_race_safely(): void
    {
        $competition = Competition::query()->create([
            'slug' => 'lifecycle-race-'.uniqid(),
            'title' => 'Lifecycle Race Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Active,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => 5,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'ends_at' => now()->subMinute(),
            'draw_at' => now()->addWeek(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        $user = User::query()->create([
            'name' => 'Race User',
            'email' => 'lifecycle-race-'.uniqid().'@example.com',
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
        OrderItem::query()->create([
            'order_id' => $order->id,
            'competition_id' => $competition->id,
            'prize_id' => null,
            'competition_title' => $competition->title,
            'quantity' => 5,
            'unit_price' => 100,
            'currency' => 'GBP',
            'line_total' => 500,
        ]);

        $closeResult = sys_get_temp_dir().'/p8_concurrency_'.uniqid().'_close.json';
        $allocateResult = sys_get_temp_dir().'/p8_concurrency_'.uniqid().'_allocate.json';

        $pidClose = $this->forkTransition($competition->id, CompetitionStatus::Closed, 'ends_at reached', $closeResult);

        $pidAllocate = pcntl_fork();
        if ($pidAllocate === -1) {
            $this->fail('pcntl_fork() failed.');
        }
        if ($pidAllocate === 0) {
            DB::purge('mysql');
            $outcome = ['success' => false];
            try {
                app(TicketAllocationService::class)->allocateOrder($order);
                $outcome['success'] = true;
            } catch (\Throwable) {
                // Either outcome is acceptable here — see assertions below.
            }
            file_put_contents($allocateResult, json_encode($outcome));
            exit(0);
        }

        pcntl_waitpid($pidClose, $statusClose);
        pcntl_waitpid($pidAllocate, $statusAllocate);

        @unlink($closeResult);
        $allocateOutcome = json_decode(file_get_contents($allocateResult), true);
        @unlink($allocateResult);

        $ticketCount = DB::connection('mysql')->table('tickets')->where('competition_id', $competition->id)->count();
        $final = Competition::query()->find($competition->id)->status;

        // Allocation never checks competition.status at all (P7) — only
        // capacity and the order's own paid status, both real hard
        // constraints; a competition closing doesn't retroactively
        // invalidate an already-paid order. So the 5-for-5 allocation
        // always succeeds here regardless of which worker's transaction
        // commits first.
        $this->assertTrue($allocateOutcome['success']);
        $this->assertSame(5, $ticketCount);

        // Whichever order the two transactions actually committed in,
        // both paths in the matrix funnel to the same place: either
        // active->closed won first (and the later active->sold_out
        // attempt no-ops because the row is no longer active), or
        // active->sold_out won first (and the closing worker's own
        // retry-under-lock sees sold_out and still succeeds via the
        // sold_out->closed rule). Never left "active", never corrupted.
        $this->assertSame(CompetitionStatus::Closed, $final);
    }
}
