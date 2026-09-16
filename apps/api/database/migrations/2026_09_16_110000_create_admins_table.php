<?php

use App\Enums\AdminRole;
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
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // App\Enums\AdminRole — plain string column, not MySQL ENUM,
            // same convention as every status/type column elsewhere in
            // this schema (CompetitionStatus, OrderStatus, ...).
            $table->string('role')->default(AdminRole::Operations->value);
            // Deactivation, not deletion, is the real product need here:
            // "was this admin account ever active" is a security-relevant
            // fact that must survive, so this table deliberately has no
            // softDeletes() — an admin is disabled via is_active, never
            // soft-deleted. See P11 architecture plan §3.
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
