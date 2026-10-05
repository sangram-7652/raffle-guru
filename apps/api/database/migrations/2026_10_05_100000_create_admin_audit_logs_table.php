<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Append-only trail of staff-account mutations (P11.12). Separate
        // from the domain audit tables (winner/draw/competition) on
        // purpose: an admin-account event is not a domain event.
        // Admins are never deleted (is_active only), so the actor and
        // target foreign keys can restrict deletion and history always
        // resolves to the admin who acted.
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_admin_id')->constrained('admins')->restrictOnDelete();
            $table->foreignId('target_admin_id')->constrained('admins')->restrictOnDelete();

            // admin.created, admin.updated, admin.role_changed,
            // admin.activated, admin.deactivated — see AdminAuditLog.
            $table->string('event', 64);

            // Structured, non-secret context only: changed field names,
            // role from/to, and an operator-entered reason. Never a
            // password, hash, token or session value.
            $table->json('metadata')->nullable();

            $table->timestamp('created_at');
            // No updated_at: append-only.

            $table->index(['target_admin_id', 'created_at']);
            $table->index('actor_admin_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
