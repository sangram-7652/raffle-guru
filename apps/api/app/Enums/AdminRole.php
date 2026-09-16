<?php

namespace App\Enums;

/**
 * Staff role on the `admins` table — see docs P11 architecture plan
 * "Authorization model". Deliberately a fixed enum, not a
 * roles/permissions package: two roles today, a small and well-
 * understood ability set per role, enforced entirely server-side via
 * Policies (never by hiding a Filament button). Plain string column,
 * not MySQL ENUM — same convention as every other status/type column
 * in this schema (CompetitionStatus, OrderStatus, WinnerStatus, ...).
 *
 * Support/Finance were considered (see the architecture plan) and
 * deliberately deferred — nothing in this phase's approved scope needs
 * more than Super Admin vs. Operations.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';

    /**
     * The centralized role → ability mapping (P11.2). Every Policy in
     * app/Policies goes through Admin::hasAbility(), which reads this
     * list — role checks are never scattered as `$admin->role === ...`
     * comparisons elsewhere in the app, so the whole authorization
     * surface is inspectable and table-testable from this one method.
     *
     * @return list<AdminAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::SuperAdmin => AdminAbility::cases(),

            // Operations gets everything an operator needs to run
            // competitions day-to-day through the existing domain
            // services, but nothing that touches admin identity itself
            // (no admin.view/admin.manage) or cross-admin audit
            // (no audit.view) — see the P11.2 role matrix. Note
            // competition.delete is deliberately withheld even though
            // "manage prizes" below is full CRUD — the approved matrix
            // grants Operations only view/create/update on competitions.
            self::Operations => [
                AdminAbility::CompetitionView,
                AdminAbility::CompetitionCreate,
                AdminAbility::CompetitionUpdate,
                AdminAbility::CompetitionLifecycle,

                AdminAbility::PrizeView,
                AdminAbility::PrizeCreate,
                AdminAbility::PrizeUpdate,
                AdminAbility::PrizeDelete,

                AdminAbility::OrderView,
                AdminAbility::PaymentView,
                AdminAbility::EntryView,
                AdminAbility::TicketView,

                AdminAbility::DrawView,
                AdminAbility::DrawRun,

                AdminAbility::WinnerView,
                AdminAbility::WinnerTransition,

                AdminAbility::ClaimView,
                AdminAbility::ClaimVerify,
                AdminAbility::ClaimApprove,
                AdminAbility::ClaimReject,

                AdminAbility::FulfilmentView,
                AdminAbility::FulfilmentAdvance,
                AdminAbility::FulfilmentAssignPrize,

                AdminAbility::CustomerView,
            ],
        };
    }
}
