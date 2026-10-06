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
        Schema::table('admins', function (Blueprint $table) {
            // Set when a SuperAdmin resets the password to a temporary one.
            // While true, the admin may only complete the forced password
            // change (see EnforceAdminSession and Admin::hasAbility).
            $table->boolean('must_change_password')->default(false);

            // Per-admin session generation. Bumped on deactivation and on
            // any password reset or change, so every session issued under an
            // older generation stops working. Stored in the session at login
            // and compared on every admin request.
            $table->unsignedInteger('session_version')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'session_version']);
        });
    }
};
