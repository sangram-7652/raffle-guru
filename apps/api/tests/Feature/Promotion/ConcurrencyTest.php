<?php

namespace Tests\Feature\Promotion;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Promotions\PromotionClaimService;
use App\Services\Promotions\PromotionNotAvailableException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Mirrors tests/Feature/Ticket/ConcurrencyTest.php exactly — genuinely
 * concurrent transactions via pcntl_fork(), against the real isolated
 * MySQL test database, never RefreshDatabase/sqlite. See
 * docs/EMAIL_CAPTURE.md "Option 1 proposal" for why the Promotion row
 * lock is the mechanism this proves.
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

    /** @var array<int, int> */
    private array $createdPromotionIds = [];

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
        // promotion_claims references entries/orders/promotions/users —
        // delete it first, before any of those parent rows.
        DB::connection('mysql')->table('promotion_claims')->whereIn('promotion_id', $this->createdPromotionIds)->delete();
        DB::connection('mysql')->table('tickets')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('entries')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('order_items')->whereIn('competition_id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('orders')->whereIn('user_id', $this->createdUserIds)->delete();
        DB::connection('mysql')->table('promotions')->whereIn('id', $this->createdPromotionIds)->delete();
        DB::connection('mysql')->table('competitions')->whereIn('id', $this->createdCompetitionIds)->delete();
        DB::connection('mysql')->table('users')->whereIn('id', $this->createdUserIds)->delete();

        Config::set('database.default', 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    /**
     * Forks a child process that attempts $user's claim on $promotion in
     * isolation, writing its outcome to $resultPath as JSON. The child
     * never returns to PHPUnit's own flow — it always exit()s.
     */
    private function forkClaim(User $user, Promotion $promotion, string $resultPath): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // Child: must not share the parent's socket/connection.
            DB::purge('mysql');

            $result = ['status' => null, 'exception' => null];

            try {
                $claim = app(PromotionClaimService::class)->claim($user, $promotion);
                $result['status'] = $claim->status->value;
            } catch (\Throwable $e) {
                $result['exception'] = $e::class;
            }

            file_put_contents($resultPath, json_encode($result));
            exit(0);
        }

        return $pid;
    }

    public function test_concurrent_claims_at_the_final_available_capacity_never_award_two_tickets(): void
    {
        $competition = Competition::query()->create([
            'slug' => 'promo-concurrency-'.uniqid(),
            'title' => 'Promo Concurrency Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Active,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => 1000,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'draw_at' => now()->addWeek(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        $promotion = Promotion::query()->create([
            'name' => 'Concurrency Promo',
            'slug' => 'concurrency-promo-'.uniqid(),
            'competition_id' => $competition->id,
            'is_enabled' => true,
            // Exactly one slot left — two different users race for it.
            'total_capacity' => 1,
        ]);
        $this->createdPromotionIds[] = $promotion->id;

        $userA = User::query()->create([
            'name' => 'Promo Concurrency A',
            'email' => 'promo-concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);
        $userB = User::query()->create([
            'name' => 'Promo Concurrency B',
            'email' => 'promo-concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);
        $this->createdUserIds[] = $userA->id;
        $this->createdUserIds[] = $userB->id;

        $resultA = sys_get_temp_dir().'/promo_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/promo_concurrency_'.uniqid().'_b.json';

        $pidA = $this->forkClaim($userA, $promotion, $resultA);
        $pidB = $this->forkClaim($userB, $promotion, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode(file_get_contents($resultA), true);
        $outcomeB = json_decode(file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // The core invariant: never more fulfilled claims than capacity,
        // no matter how the two concurrent requests interleaved.
        $fulfilledCount = DB::connection('mysql')->table('promotion_claims')
            ->where('promotion_id', $promotion->id)
            ->where('status', 'fulfilled')
            ->count();
        $this->assertSame(1, $fulfilledCount);

        $ticketCount = DB::connection('mysql')->table('tickets')
            ->where('competition_id', $competition->id)
            ->count();
        $this->assertSame(1, $ticketCount, 'Exactly one ticket should ever be allocated for one unit of capacity.');

        // Exactly one side fulfils; the other's claim() call throws
        // PromotionNotAvailableException (capacity exhausted) rather
        // than returning a claim — never both, never neither.
        $fulfilled = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['status'] === 'fulfilled');
        $rejected = array_filter([$outcomeA, $outcomeB], fn ($o) => $o['exception'] !== null);
        $this->assertCount(1, $fulfilled, 'Exactly one of the two concurrent claims should fulfil.');
        $this->assertCount(1, $rejected, 'Exactly one of the two concurrent claims should be rejected.');
        $this->assertSame(
            PromotionNotAvailableException::class,
            array_values($rejected)[0]['exception'],
        );

        // Exactly two claim rows exist — one per user, never a
        // duplicate for either.
        $this->assertSame(
            2,
            DB::connection('mysql')->table('promotion_claims')->where('promotion_id', $promotion->id)->count(),
        );
    }

    public function test_concurrent_duplicate_claims_by_the_same_user_never_create_two_rows(): void
    {
        $competition = Competition::query()->create([
            'slug' => 'promo-dup-concurrency-'.uniqid(),
            'title' => 'Promo Duplicate Concurrency Test',
            'short_description' => 'Test',
            'description' => ['Test'],
            'category' => 'Cars',
            'status' => CompetitionStatus::Active,
            'entry_price' => 100,
            'currency' => 'GBP',
            'max_entries' => 1000,
            'max_entries_per_user' => null,
            'images' => ['https://example.com/x.jpg'],
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B']],
            'draw_at' => now()->addWeek(),
            'is_featured' => false,
        ]);
        $this->createdCompetitionIds[] = $competition->id;

        $promotion = Promotion::query()->create([
            'name' => 'Duplicate Concurrency Promo',
            'slug' => 'duplicate-concurrency-promo-'.uniqid(),
            'competition_id' => $competition->id,
            'is_enabled' => true,
            'total_capacity' => 100,
        ]);
        $this->createdPromotionIds[] = $promotion->id;

        $user = User::query()->create([
            'name' => 'Promo Duplicate Concurrency',
            'email' => 'promo-dup-concurrency-'.uniqid().'@example.com',
            'password' => 'irrelevant-not-logged-in-with',
            'email_verified_at' => now(),
        ]);
        $this->createdUserIds[] = $user->id;

        $resultA = sys_get_temp_dir().'/promo_dup_concurrency_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/promo_dup_concurrency_'.uniqid().'_b.json';

        // Same user, same promotion, two simultaneous requests — e.g. a
        // double-click.
        $pidA = $this->forkClaim($user, $promotion, $resultA);
        $pidB = $this->forkClaim($user, $promotion, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        @unlink($resultA);
        @unlink($resultB);

        $this->assertSame(
            1,
            DB::connection('mysql')->table('promotion_claims')
                ->where('promotion_id', $promotion->id)
                ->where('user_id', $user->id)
                ->count(),
            'A double-submit from the same user must never create a second claim row.',
        );
        $this->assertSame(
            1,
            DB::connection('mysql')->table('tickets')->where('competition_id', $competition->id)->where('user_id', $user->id)->count(),
        );
    }
}
