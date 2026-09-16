<?php

namespace App\Filament\Widgets;

use App\Models\CompetitionLifecycleEvent;
use App\Models\DrawAuditLog;
use App\Models\WinnerAuditLog;
use Filament\Widgets\Widget;

/**
 * A read-only recent-activity feed drawn from the three existing,
 * immutable audit tables (competition_lifecycle_events, draw_audit_logs,
 * winner_audit_logs) — P11.4 §9. Nothing here writes to any of them.
 *
 * These three tables have different shapes (competition_lifecycle_events
 * has from/to status + a plain `reason` column; draw_audit_logs has a
 * free-form `event` string + JSON metadata; winner_audit_logs adds
 * actor_type/actor_id on top of that) — forcing them into one SQL UNION
 * would mean padding every table out to a common column set for no
 * real benefit. Per §9 ("a clearly separated or simplified recent
 * activity presentation is acceptable"), this instead runs three
 * independent, already-bounded queries and merges the results in PHP:
 *
 *   SELECT * FROM <table> ORDER BY created_at DESC LIMIT 10
 *
 * Each one is index-backed (competition_lifecycle_events_created_at_index
 * / draw_audit_logs_created_at_index / winner_audit_logs_created_at_index
 * — added this milestone, see the migration) and bounded at the database
 * layer before PHP ever sees a row. Merging and re-sorting at most 30
 * already-limited rows in PHP is not the "load a large table into memory"
 * pattern §11 prohibits — it's three small, fixed-size result sets.
 */
class RecentActivityWidget extends Widget
{
    protected string $view = 'filament.widgets.recent-activity-widget';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 7;

    private const PER_SOURCE_LIMIT = 10;

    private const DISPLAY_LIMIT = 15;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $lifecycleEvents = CompetitionLifecycleEvent::query()
            ->latest('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (CompetitionLifecycleEvent $event) => [
                'event' => trim(($event->from_status ?? '—')." → {$event->to_status}"),
                'target' => "Competition #{$event->competition_id}",
                'reason' => $event->reason,
                'timestamp' => $event->created_at,
            ]);

        $drawEvents = DrawAuditLog::query()
            ->latest('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (DrawAuditLog $log) => [
                'event' => $log->event,
                'target' => "Draw #{$log->draw_id} (Competition #{$log->competition_id})",
                'reason' => $log->metadata['reason'] ?? null,
                'timestamp' => $log->created_at,
            ]);

        $winnerEvents = WinnerAuditLog::query()
            ->latest('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (WinnerAuditLog $log) => [
                'event' => $log->event,
                'target' => "Winner #{$log->winner_id}",
                'reason' => $log->metadata['reason'] ?? null,
                'timestamp' => $log->created_at,
            ]);

        $activity = $lifecycleEvents
            ->concat($drawEvents)
            ->concat($winnerEvents)
            ->sortByDesc('timestamp')
            ->take(self::DISPLAY_LIMIT)
            ->values();

        return [
            'activity' => $activity,
        ];
    }
}
