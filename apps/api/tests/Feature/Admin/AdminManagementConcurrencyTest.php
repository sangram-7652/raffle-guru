<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminManagementService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * P11.12 — the last-active-SuperAdmin invariant under REAL concurrent
 * MySQL transactions. Each racing request runs in a pcntl_fork()'d child
 * with its own connection, released together by a start gate so the
 * transactions genuinely overlap. Same technique as WinnerConcurrencyTest.
 *
 * Only ever runs against the isolated `raffleguru_testing` database; the
 * guard below refuses to touch anything else.
 */
class AdminManagementConcurrencyTest extends TestCase
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
            throw new RuntimeException('Refusing to run admin concurrency tests outside raffleguru_testing.');
        }
    }

    /**
     * Only the isolated test database is wiped, and only after the name
     * check above has passed.
     */
    private function wipeAdmins(): void
    {
        $this->assertSafeDatabase();

        DB::connection('mysql')->table('admin_audit_logs')->delete();
        DB::connection('mysql')->table('admins')->delete();
    }

    private function makeAdmin(AdminRole $role, bool $active = true, string $name = 'Race'): Admin
    {
        return Admin::query()->create([
            'name' => $name,
            'email' => $name.'-'.uniqid().'@example.com',
            'password' => 'Correct-Horse-9',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    /**
     * Forks one child per work item. Each child waits on a start gate, then
     * runs its work and writes {ok, error} to a result file.
     *
     * @param  array<int, \Closure():mixed>  $work
     * @return array<int, array<string, mixed>>
     */
    private function raceAll(array $work): array
    {
        $gate = sys_get_temp_dir().'/p11_12_gate_'.uniqid();
        $results = [];
        $pids = [];

        foreach ($work as $index => $unit) {
            $resultPath = sys_get_temp_dir().'/p11_12_result_'.uniqid().'_'.$index.'.json';
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

    private function activeSuperAdmins(): int
    {
        return DB::connection('mysql')->table('admins')
            ->where('role', AdminRole::SuperAdmin->value)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Two SuperAdmins deactivate each other at the same moment. Without the
     * lock both reads could see two actives and both writes would commit,
     * leaving zero. With it, exactly one succeeds.
     */
    public function test_two_super_admins_deactivating_each_other_leave_one_active(): void
    {
        $a = $this->makeAdmin(AdminRole::SuperAdmin, name: 'SA-A');
        $b = $this->makeAdmin(AdminRole::SuperAdmin, name: 'SA-B');

        $outcomes = $this->raceAll([
            fn () => app(AdminManagementService::class)->deactivate($a, $b, 'Race A on B'),
            fn () => app(AdminManagementService::class)->deactivate($b, $a, 'Race B on A'),
        ]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count(), json_encode($outcomes));
        $this->assertGreaterThanOrEqual(1, $this->activeSuperAdmins(), 'Zero active SuperAdmins after the race.');
        $this->assertSame(1, $this->activeSuperAdmins());
    }

    /**
     * One SuperAdmin demotes another while the other deactivates the first.
     * Either order is fine; both succeeding is not.
     */
    public function test_demoting_and_deactivating_each_other_preserves_an_active_super_admin(): void
    {
        $a = $this->makeAdmin(AdminRole::SuperAdmin, name: 'SA-C');
        $b = $this->makeAdmin(AdminRole::SuperAdmin, name: 'SA-D');

        $outcomes = $this->raceAll([
            fn () => app(AdminManagementService::class)->changeRole($a, $b, AdminRole::Operations, 'Race demote B'),
            fn () => app(AdminManagementService::class)->deactivate($b, $a, 'Race deactivate A'),
        ]);

        // Serialized either way: whichever commits first removes the other's
        // authority (B is demoted, or A is deactivated), so the second is refused.
        $this->assertSame(1, collect($outcomes)->where('ok', true)->count(), json_encode($outcomes));
        $this->assertSame(1, $this->activeSuperAdmins());
    }

    /**
     * Two requests create the same email at once. Exactly one admin row
     * must exist afterwards.
     */
    public function test_concurrent_creates_with_the_same_email_produce_one_admin(): void
    {
        $actor = $this->makeAdmin(AdminRole::SuperAdmin, name: 'Creator');
        $data = [
            'name' => 'Duplicate Race',
            'email' => 'duplicate-race@example.com',
            'role' => AdminRole::Operations->value,
            'password' => 'Correct-Horse-9',
        ];

        $outcomes = $this->raceAll([
            fn () => app(AdminManagementService::class)->create($actor, $data)->id,
            fn () => app(AdminManagementService::class)->create($actor, $data)->id,
        ]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count(), json_encode($outcomes));
        $this->assertSame(1, DB::connection('mysql')->table('admins')->where('email', 'duplicate-race@example.com')->count());
        $this->assertSame(1, AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_CREATED)->count());
    }

    /**
     * The invariant is checked against the state the lock reveals, not the
     * state the request started with: a stale SuperAdmin object cannot
     * deactivate someone after they have already been removed.
     */
    public function test_a_stale_actor_cannot_act_after_being_deactivated_by_a_race(): void
    {
        $a = $this->makeAdmin(AdminRole::SuperAdmin, name: 'SA-E');
        $b = $this->makeAdmin(AdminRole::SuperAdmin, name: 'SA-F');
        $target = $this->makeAdmin(AdminRole::Operations, name: 'Target');

        $outcomes = $this->raceAll([
            fn () => app(AdminManagementService::class)->deactivate($b, $a, 'Remove A'),
            fn () => app(AdminManagementService::class)->changeRole($a, $target, AdminRole::SuperAdmin, 'Stale A acting'),
        ]);

        $this->assertGreaterThanOrEqual(1, $this->activeSuperAdmins(), json_encode($outcomes));

        // Audit ids are issued in lock order, so they give the serial order of
        // the two commits. A's role change must not be recorded AFTER A was
        // deactivated. Whichever order won, the outcome must match it.
        $roleChange = AdminAuditLog::query()
            ->where('event', AdminAuditLog::EVENT_ROLE_CHANGED)
            ->where('actor_admin_id', $a->id)
            ->first();
        $deactivation = AdminAuditLog::query()
            ->where('event', AdminAuditLog::EVENT_DEACTIVATED)
            ->where('target_admin_id', $a->id)
            ->sole();

        if ($roleChange !== null) {
            $this->assertLessThan($deactivation->id, $roleChange->id, 'A changed a role after being deactivated.');
        }

        $this->assertSame($roleChange !== null, $outcomes[1]['ok'], json_encode($outcomes));
    }
}
