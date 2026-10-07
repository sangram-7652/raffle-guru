<?php

namespace Tests\Feature\Ticket;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\CapacityExceededException;
use App\Services\Tickets\PerUserLimitExceededException;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Runs against a REAL MySQL database — the isolated
 * TestCase::MYSQL_TEST_DATABASE, never the development database — via
 * pcntl_fork()'d child processes — genuinely concurrent transactions
 * cannot be exercised within one single-threaded PHP process/connection,
 * and sequential calls prove nothing about the row-lock strategy. See
 * docs/TICKET_ENGINE_DESIGN_P7.md "Transaction & locking strategy".
 *
 * Deliberately does NOT use RefreshDatabase (which only ever targets the
 * sqlite `:memory:` testing connection per phpunit.xml) — it switches
 * the app's default connection to the real 'mysql' one for its own
 * duration, creates its own fixtures, and cleans them up explicitly in
 * tearDown.
 *
 * #[Group('mysql')] (P11.13): see AdminManagementConcurrencyTest.
 */
#[Group('mysql')]
class ConcurrencyTest extends TestCase
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

        // Host/port/credentials for 'mysql' come from the real
        // environment; the database is always the isolated *_testing one.
        $this->useIsolatedMysqlDatabase();
    }

    protected function tearDown(): void
    {
        DB::connection('mysql')->table('tickets')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('entries')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('order_items')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('orders')->whereIn('user_id', $this->createdUserIds)->delete();
        DB::connection('mysql')->table('competitions')->whereIn('id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('users')->whereIn('id', $this->createdUserIds)->delete();

        // Defensive: Laravel's per-test application rebuild already
        // prevents this Config::set() from leaking into other test
        // methods, but restore it explicitly anyway rather than rely on
        // that alone.
        Config::set('database.default', 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    /**
     * Forks a child process that allocates $order in isolation, writing
     * its outcome to $resultPath as JSON. The child never returns to
     * PHPUnit's own flow — it always exit()s.
     */
    private function forkAllocation(Order $order, string $resultPath): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // Child: must not share the parent's socket/connection.
            DB::purge('mysql');

            $result = ['success' => false, 'exception' => null];

            try {
                app(TicketAllocationService::class)->allocateOrder($order);
                $result['success'] = true;
            } catch (\Throwable $e) {
                $result['exception'] = $e::class;
            }

            file_put_contents($resultPath, json_encode($result));
            exit(0);
        }

        return $pid;
    }

    public function test_concurrent_allocation_never_exceeds_competition_capacity(): void
    {
        $competition = Competition::query()->create([
            'slug' => 'concurrency-capacity-'.uniqid(),
            'title' => 'Concurrency Capacity Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Active,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => 10,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'draw_at' => now()->addWeek(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        [$orderA, $userA] = $this->paidOrderFor($competition, 6);
        [$orderB, $userB] = $this->paidOrderFor($competition, 6);
        $this->createdUserIds[] = $userA->id;
        $this->createdUserIds[] = $userB->id;

        $resultA = sys_get_temp_dir().'/p7_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p7_concurrency_'.uniqid().'_b.json';

        $pidA = $this->forkAllocation($orderA, $resultA);
        $pidB = $this->forkAllocation($orderB, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // The core invariant: never more tickets than capacity, no
        // matter how the two concurrent 6-ticket requests interleaved.
        $ticketCount = DB::connection('mysql')->table('tickets')
            ->where('competition_id', $competition->id)
            ->count();
        $this->assertLessThanOrEqual(10, $ticketCount);

        // 6 + 6 = 12 > 10 — they cannot BOTH succeed. Exactly one must
        // have been allocated (6 tickets) and the other must have been
        // rejected with CapacityExceededException, not merely "some
        // partial amount" — no partial allocation is ever acceptable.
        $successes = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['success']);
        $failures = array_filter([$outcomeA, $outcomeB], fn ($o) => ! $o['success']);

        $this->assertCount(1, $successes, 'Exactly one of the two concurrent allocations should succeed.');
        $this->assertCount(1, $failures, 'Exactly one of the two concurrent allocations should fail.');
        $this->assertSame(
            CapacityExceededException::class,
            array_values($failures)[0]['exception'],
        );
        $this->assertSame(6, $ticketCount);

        // The failed order must have zero entries/tickets — no partial
        // allocation for it.
        $failedOrder = $outcomeA['success'] ? $orderB : $orderA;
        $this->assertSame(
            0,
            DB::connection('mysql')->table('entries')->where('order_id', $failedOrder->id)->count(),
        );
    }

    public function test_concurrent_allocation_never_exceeds_per_user_limit(): void
    {
        $competition = Competition::query()->create([
            'slug' => 'concurrency-per-user-'.uniqid(),
            'title' => 'Concurrency Per-User Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Active,
            'entry_price' => 100,
            'currency' => 'GBP',
            // Large enough that the capacity check never fires — this
            // test is isolating the per-user-limit check specifically.
            'max_entries' => 100000,
            'max_entries_per_user' => 10,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'draw_at' => now()->addWeek(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        // Same user places two SEPARATE paid orders (e.g. two browser
        // tabs), each requesting 6 — together they'd exceed the
        // per-user limit of 10.
        $user = User::query()->create([
            'name' => 'Concurrency User',
            'email' => 'concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);
        $this->createdUserIds[] = $user->id;

        [$orderA] = $this->paidOrderFor($competition, 6, $user);
        [$orderB] = $this->paidOrderFor($competition, 6, $user);

        $resultA = sys_get_temp_dir().'/p7_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p7_concurrency_'.uniqid().'_b.json';

        $pidA = $this->forkAllocation($orderA, $resultA);
        $pidB = $this->forkAllocation($orderB, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        $ticketCount = DB::connection('mysql')->table('tickets')
            ->where('competition_id', $competition->id)
            ->where('user_id', $user->id)
            ->count();
        $this->assertLessThanOrEqual(10, $ticketCount);

        $successes = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['success']);
        $failures = array_filter([$outcomeA, $outcomeB], fn ($o) => ! $o['success']);
        $this->assertCount(1, $successes, 'Exactly one of the two concurrent allocations should succeed.');
        $this->assertCount(1, $failures, 'Exactly one of the two concurrent allocations should fail.');
        $this->assertSame(
            PerUserLimitExceededException::class,
            array_values($failures)[0]['exception'],
        );
        $this->assertSame(6, $ticketCount);
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function paidOrderFor(Competition $competition, int $quantity, ?User $user = null): array
    {
        $user ??= User::query()->create([
            'name' => 'Concurrency User',
            'email' => 'concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'reference' => 'RG-'.random_int(100000, 999999),
            'status' => OrderStatus::Paid,
            'currency' => 'GBP',
            'subtotal' => $competition->entry_price * $quantity,
            'total' => $competition->entry_price * $quantity,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'competition_id' => $competition->id,
            'prize_id' => null,
            'competition_title' => $competition->title,
            'quantity' => $quantity,
            'unit_price' => $competition->entry_price,
            'currency' => 'GBP',
            'line_total' => $competition->entry_price * $quantity,
        ]);

        return [$order, $user];
    }
}
