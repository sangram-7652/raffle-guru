<?php

namespace App\Filament\Support;

use App\Enums\CompetitionStatus;
use App\Models\Competition;

/**
 * "Has this competition never been purchasable?" — the one state gate
 * P11.5's destructive/structural admin actions share. Draft and
 * Scheduled are the only statuses a competition can hold before its
 * first `active` (CompetitionLifecycleService has no backward edges),
 * and scopePurchasable() only ever admits `active` — so a pre-launch
 * competition provably has no orders, tickets, draws or winners
 * pointing at it.
 *
 * Used for:
 * - soft-deleting a competition (history must stay intact once live —
 *   see the competitions migration's softDeletes() comment);
 * - adding/removing prizes (PrizeResolutionService resolves a winner's
 *   prize only when the competition has exactly one prize row, and
 *   WinnerResource reads delivery->prize — changing the prize set after
 *   launch changes what customers bought and can break fulfilment).
 *
 * This is an admin-panel guard, not a lifecycle rule — it never changes
 * status and never duplicates CompetitionLifecycleService's matrix.
 */
final class CompetitionPreLaunch
{
    /**
     * @return list<CompetitionStatus>
     */
    public static function statuses(): array
    {
        return [CompetitionStatus::Draft, CompetitionStatus::Scheduled];
    }

    public static function is(?Competition $competition): bool
    {
        return $competition !== null
            && ! $competition->trashed()
            && in_array($competition->status, self::statuses(), true);
    }
}
