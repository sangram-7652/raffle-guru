<?php

use App\Enums\PaymentStatus;
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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Financial/audit record — restrictOnDelete, same reasoning as
            // orders->users (P5): never let a payment attempt disappear
            // out from under its order.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // Plain string, not a MySQL ENUM — room for a second gateway
            // later without a migration (see docs/PAYMENTS_DESIGN_P6.md).
            $table->string('gateway');
            $table->string('gateway_order_id');
            $table->string('gateway_payment_id')->nullable();
            // Not a secret itself (a signature over public ids), kept only
            // for audit — never re-derived/relied on after verification.
            $table->string('gateway_signature')->nullable();
            // Minor units (pence), resolved only from Order.total — never
            // from the client. See docs/PAYMENTS_DESIGN_P6.md.
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            $table->string('status')->default(PaymentStatus::Created->value)->index();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            // Curated, non-sensitive gateway metadata only (method, email,
            // contact) — never card/bank/UPI credentials, which this
            // application never receives in the first place.
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A gateway order id is only ever created once by this app, and
            // a gateway payment id (once known) identifies exactly one
            // payment at that gateway — enforced by the database, not just
            // application logic. Both are scoped by `gateway` since ids
            // from different providers are never assumed to share a
            // namespace (see docs/PAYMENTS_DESIGN_P6.md "Step 3").
            $table->unique(['gateway', 'gateway_order_id']);
            $table->unique(['gateway', 'gateway_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
