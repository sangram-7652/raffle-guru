<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P11.14 — `php artisan admin:create-super` and
 * AdminManagementService::bootstrapSuperAdmin(). Logic-only coverage;
 * the concurrency invariant itself (two bootstrap attempts racing
 * against a genuinely empty `admins` table) needs real MySQL locking
 * and is covered separately in AdminBootstrapConcurrencyTest
 * (`#[Group('mysql')]`) — SQLite's per-connection `:memory:` database
 * can't exercise cross-connection locking at all, so a "concurrency"
 * test here would prove nothing (same reasoning as every other
 * `#[Group('mysql')]` test since P11.13).
 */
class AdminBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AdminManagementService
    {
        return app(AdminManagementService::class);
    }

    // -- Command: happy path --------------------------------------------------

    public function test_command_creates_the_first_super_admin(): void
    {
        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'founder@example.com')
            ->expectsQuestion('Password', 'Correct-Horse-9')
            ->expectsQuestion('Confirm password', 'Correct-Horse-9')
            ->assertExitCode(0);

        $admin = Admin::query()->where('email', 'founder@example.com')->sole();
        $this->assertSame(AdminRole::SuperAdmin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->must_change_password);
    }

    public function test_command_refuses_when_passwords_do_not_match(): void
    {
        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'founder@example.com')
            ->expectsQuestion('Password', 'Correct-Horse-9')
            ->expectsQuestion('Confirm password', 'Something-Else-9')
            ->assertExitCode(1);

        $this->assertSame(0, Admin::query()->count());
    }

    // -- Command: refusal when a SuperAdmin already exists -------------------

    public function test_command_refuses_before_prompting_when_a_super_admin_already_exists(): void
    {
        $existing = Admin::factory()->superAdmin()->create(['email' => 'existing-super@example.com']);

        $this->artisan('admin:create-super')->assertExitCode(1);

        $this->assertSame(1, Admin::query()->count());
        $this->assertSame('existing-super@example.com', $existing->fresh()->email);
    }

    public function test_an_existing_operations_admin_alone_does_not_block_bootstrap(): void
    {
        // The invariant is "no SuperAdmin", not "no admin at all" — an
        // Operations-only roster (e.g. seeded staff with nobody promoted
        // yet) must still be bootstrappable.
        Admin::factory()->create(['role' => AdminRole::Operations]);

        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'founder@example.com')
            ->expectsQuestion('Password', 'Correct-Horse-9')
            ->expectsQuestion('Confirm password', 'Correct-Horse-9')
            ->assertExitCode(0);

        $this->assertSame(2, Admin::query()->count());
        $this->assertSame(
            AdminRole::SuperAdmin,
            Admin::query()->where('email', 'founder@example.com')->sole()->role,
        );
    }

    // -- Command: invalid input ------------------------------------------------

    public function test_command_reports_invalid_email(): void
    {
        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'not-an-email')
            ->expectsQuestion('Password', 'Correct-Horse-9')
            ->expectsQuestion('Confirm password', 'Correct-Horse-9')
            ->assertExitCode(1);

        $this->assertSame(0, Admin::query()->count());
    }

    public function test_command_reports_a_weak_password(): void
    {
        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'founder@example.com')
            ->expectsQuestion('Password', '123')
            ->expectsQuestion('Confirm password', '123')
            ->assertExitCode(1);

        $this->assertSame(0, Admin::query()->count());
    }

    // -- Command: repeatable ---------------------------------------------------

    public function test_command_can_be_safely_invoked_again_after_bootstrap(): void
    {
        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'founder@example.com')
            ->expectsQuestion('Password', 'Correct-Horse-9')
            ->expectsQuestion('Confirm password', 'Correct-Horse-9')
            ->assertExitCode(0);

        $this->artisan('admin:create-super')->assertExitCode(1);
        $this->artisan('admin:create-super')->assertExitCode(1);

        $this->assertSame(1, Admin::query()->count());
    }

    // -- Command: no password leakage ------------------------------------------

    public function test_command_output_never_contains_the_plaintext_password(): void
    {
        $plaintext = 'Correct-Horse-Battery-9';

        $this->artisan('admin:create-super')
            ->expectsQuestion('Name', 'Founding Admin')
            ->expectsQuestion('Email', 'founder@example.com')
            ->expectsQuestion('Password', $plaintext)
            ->expectsQuestion('Confirm password', $plaintext)
            ->doesntExpectOutputToContain($plaintext)
            ->assertExitCode(0);
    }

    // -- Service: direct coverage -----------------------------------------------

    public function test_service_creates_a_super_admin_with_a_hashed_password(): void
    {
        $admin = $this->service()->bootstrapSuperAdmin([
            'name' => 'Founding Admin',
            'email' => 'founder@example.com',
            'password' => 'Correct-Horse-9',
        ]);

        $this->assertSame(AdminRole::SuperAdmin, $admin->role);
        $this->assertTrue($admin->is_active);

        $hash = Admin::query()->whereKey($admin->id)->value('password');
        $this->assertNotSame('Correct-Horse-9', $hash);
        $this->assertTrue(Hash::check('Correct-Horse-9', $hash));
    }

    public function test_service_refuses_when_a_super_admin_already_exists(): void
    {
        $existing = Admin::factory()->superAdmin()->create();

        $this->expectException(AdminManagementException::class);

        try {
            $this->service()->bootstrapSuperAdmin([
                'name' => 'Second Founder',
                'email' => 'second@example.com',
                'password' => 'Correct-Horse-9',
            ]);
        } finally {
            $this->assertSame(1, Admin::query()->count());
            $this->assertSame($existing->email, $existing->fresh()->email);
        }
    }

    public function test_service_rejects_invalid_input(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->bootstrapSuperAdmin([
            'name' => '',
            'email' => 'not-an-email',
            'password' => '123',
        ]);
    }

    // -- Audit ------------------------------------------------------------------

    public function test_bootstrap_writes_a_self_referential_audit_record_with_no_secret_metadata(): void
    {
        $admin = $this->service()->bootstrapSuperAdmin([
            'name' => 'Founding Admin',
            'email' => 'founder@example.com',
            'password' => 'Correct-Horse-9',
        ]);

        $log = AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_CREATED)->sole();

        $this->assertSame($admin->id, $log->actor_admin_id);
        $this->assertSame($admin->id, $log->target_admin_id);
        $this->assertSame('bootstrap_command', $log->metadata['source']);

        $encoded = json_encode($log->metadata);
        $this->assertStringNotContainsString('Correct-Horse-9', $encoded);
        $this->assertArrayNotHasKey('password', $log->metadata);
        $this->assertArrayNotHasKey('session_version', $log->metadata);
        $this->assertArrayNotHasKey('remember_token', $log->metadata);
    }

    public function test_a_failed_bootstrap_attempt_writes_no_audit_record(): void
    {
        Admin::factory()->superAdmin()->create();

        try {
            $this->service()->bootstrapSuperAdmin([
                'name' => 'Second Founder',
                'email' => 'second@example.com',
                'password' => 'Correct-Horse-9',
            ]);
        } catch (AdminManagementException) {
            // expected
        }

        $this->assertSame(0, AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_CREATED)->count());
    }

    public function test_bootstrap_still_enforces_email_uniqueness(): void
    {
        Admin::factory()->create(['role' => AdminRole::Operations, 'email' => 'taken@example.com']);

        $this->expectException(ValidationException::class);

        $this->service()->bootstrapSuperAdmin([
            'name' => 'Founding Admin',
            'email' => 'taken@example.com',
            'password' => 'Correct-Horse-9',
        ]);
    }
}
