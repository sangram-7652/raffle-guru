<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminManagementService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * P11.14 — the bootstrap invariant ("no SuperAdmin may already exist")
 * under REAL concurrent MySQL transactions, against a genuinely EMPTY
 * `admins` table. This is the one scenario the rest of P11.12's locking
 * (AdminManagementConcurrencyTest) never exercises: every other method
 * there locks EXISTING rows, which SQLite can't do across connections
 * either, but which also isn't the hazard bootstrap has — on the very
 * first bootstrap there is no row to lock yet. If
 * AdminManagementService::bootstrapSuperAdmin()'s unfiltered
 * lockForUpdate() scan didn't take InnoDB's end-of-table gap lock on an
 * empty table, two concurrent `admin:create-super` runs could both pass
 * the "no SuperAdmin exists" check and both create one.
 *
 * Same forked-process technique as AdminManagementConcurrencyTest /
 * WinnerConcurrencyTest: each racing attempt runs in its own
 * pcntl_fork()'d child with its own connection, released together by a
 * start gate so the transactions genuinely overlap.
 *
 * #[Group('mysql')] (P11.13 convention): excluded from the fast SQLite
 * job, where forked children never share a connection with the parent's
 * `:memory:` database and the row/gap-locking this test exists to
 * verify cannot occur at all.
 */
#[Group('mysql')]
class AdminBootstrapConcurrencyTest extends TestCase
{
    private const MYSQL_TEST_DATABASE_NAME = 'raffleguru_testing';

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available — cannot fork for a genuine concurrency test.');
        }

        $this->useIsolatedMysqlDatabase();
        $this->assertSafeDatabase();
        $this->wipeAdmins();
    }

    protected function tearDown(): void
    {
        if (DB::connection('mysql')->getDatabaseName() === self::MYSQL_TEST_DATABASE_NAME) {
            $this->wipeAdmins();
        }

        Config::set('database.default', 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    private function assertSafeDatabase(): void
    {
        if (DB::connection('mysql')->getDatabaseName() !== self::MYSQL_TEST_DATABASE_NAME) {
            throw new RuntimeException('Refusing to run admin bootstrap concurrency tests outside raffleguru_testing.');
        }
    }

    private function wipeAdmins(): void
    {
        $this->assertSafeDatabase();

        DB::connection('mysql')->table('admin_audit_logs')->delete();
        DB::connection('mysql')->table('admins')->delete();
    }

    /**
     * Forks one child per work item. Each child waits on a start gate, then
     * runs its work and writes {ok, error} to a result file. Identical to
     * AdminManagementConcurrencyTest::raceAll() (P11.12) — duplicated
     * rather than extracted into a shared trait, matching how
     * WinnerConcurrencyTest/DrawConcurrencyTest/LifecycleConcurrencyTest/
     * Ticket\ConcurrencyTest already each carry their own copy.
     *
     * @param  array<int, \Closure():mixed>  $work
     * @return array<int, array<string, mixed>>
     */
    private function raceAll(array $work): array
    {
        $gate = sys_get_temp_dir().'/p11_14_gate_'.uniqid();
        $results = [];
        $pids = [];

        foreach ($work as $index => $unit) {
            $resultPath = sys_get_temp_dir().'/p11_14_result_'.uniqid().'_'.$index.'.json';
            $results[$index] = $resultPath;

            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                DB::purge('mysql');

                $deadline = microtime(true) + 10;
                while (! file_exists($gate) && microtime(true) < $deadline) {
                    usleep(500);
                }

                try {
                    $outcome = ['ok' => true, 'value' => $unit()];
                } catch (\Throwable $e) {
                    $outcome = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
                }

                file_put_contents($resultPath, json_encode($outcome));
                exit(0);
            }

            $pids[] = $pid;
        }

        touch($gate);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        @unlink($gate);

        $outcomes = [];
        foreach ($results as $index => $path) {
            $outcomes[$index] = json_decode((string) @file_get_contents($path), true) ?? ['ok' => false, 'error' => 'no result'];
            @unlink($path);
        }

        return $outcomes;
    }

    /**
     * The core invariant: two bootstrap attempts fired at the same moment
     * against a completely empty `admins` table must not both succeed.
     */
    public function test_two_concurrent_bootstrap_attempts_create_exactly_one_super_admin(): void
    {
        $this->assertSame(0, DB::connection('mysql')->table('admins')->count(), 'Must start from a genuinely empty table.');

        $outcomes = $this->raceAll([
            fn () => app(AdminManagementService::class)->bootstrapSuperAdmin([
                'name' => 'Founder A',
                'email' => 'founder-a@example.com',
                'password' => 'Correct-Horse-9',
            ])->id,
            fn () => app(AdminManagementService::class)->bootstrapSuperAdmin([
                'name' => 'Founder B',
                'email' => 'founder-b@example.com',
                'password' => 'Correct-Horse-9',
            ])->id,
        ]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count(), json_encode($outcomes));
        $this->assertSame(
            1,
            DB::connection('mysql')->table('admins')->where('role', AdminRole::SuperAdmin->value)->count(),
            'Exactly one SuperAdmin must exist after the race, never zero or two.',
        );
        $this->assertSame(1, DB::connection('mysql')->table('admins')->count(), 'No partial/duplicate row from the losing attempt.');
        $this->assertSame(
            1,
            AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_CREATED)->count(),
            'Exactly one audit row — the losing attempt must not have written one.',
        );
    }

    /**
     * Same race, but one of the two concurrent callers is also racing
     * against an Operations admin already on the roster — proves the
     * empty-table gap lock still serializes correctly once the table
     * genuinely has a row in it (Operations, not SuperAdmin), not just
     * when it's completely empty.
     */
    public function test_bootstrap_race_is_still_serialized_with_an_existing_operations_admin_present(): void
    {
        Admin::query()->create([
            'name' => 'Existing Operations',
            'email' => 'existing-ops@example.com',
            'password' => 'Correct-Horse-9',
            'role' => AdminRole::Operations,
            'is_active' => true,
        ]);

        $outcomes = $this->raceAll([
            fn () => app(AdminManagementService::class)->bootstrapSuperAdmin([
                'name' => 'Founder A',
                'email' => 'founder-a@example.com',
                'password' => 'Correct-Horse-9',
            ])->id,
            fn () => app(AdminManagementService::class)->bootstrapSuperAdmin([
                'name' => 'Founder B',
                'email' => 'founder-b@example.com',
                'password' => 'Correct-Horse-9',
            ])->id,
        ]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count(), json_encode($outcomes));
        $this->assertSame(
            1,
            DB::connection('mysql')->table('admins')->where('role', AdminRole::SuperAdmin->value)->count(),
        );
        $this->assertSame(2, DB::connection('mysql')->table('admins')->count(), 'The pre-existing Operations admin plus exactly one new SuperAdmin.');
    }
}
