<?php

namespace App\Filament\Support;

use App\Models\WinnerAuditLog;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The P10 winner audit trail (winner_audit_logs), shared by the winner
 * and claim views. Visible only with audit.view (WinnerAuditLogPolicy) —
 * Operations doesn't hold it. Shows curated fields and the actor
 * (P10's actor_type/actor_id); never raw metadata.
 */
final class WinnerAuditTrail
{
    /**
     * @param  \Closure(mixed): int|null  $winnerId
     */
    public static function section(\Closure $winnerId): Section
    {
        // Filament evaluates a RepeatableEntry's state() once per child
        // component render — memoise so the audit rows load once per page.
        $cache = [];

        return Section::make('Audit trail')
            ->columnSpanFull()
            ->collapsible()
            ->visible(fn (): bool => (bool) Auth::guard('admin')->user()?->can('viewAny', WinnerAuditLog::class))
            ->components([
                RepeatableEntry::make('winner_audit')
                    ->hiddenLabel()
                    ->state(function ($record) use ($winnerId, &$cache) {
                        $id = $winnerId($record);

                        return $cache[$id] ??= WinnerAuditLog::query()
                            ->where('winner_id', $id)
                            ->orderBy('id')
                            ->limit(100)
                            ->get();
                    })
                    ->columns(4)
                    ->placeholder('No audit events.')
                    ->schema([
                        TextEntry::make('event')->formatStateUsing(fn (string $state): string => Str::headline($state)),
                        TextEntry::make('transition')
                            ->state(fn (WinnerAuditLog $log): ?string => $log->to_status === null ? null
                                : ($log->from_status ? Str::headline($log->from_status).' → ' : '').Str::headline($log->to_status))
                            ->placeholder('—'),
                        TextEntry::make('actor')
                            ->state(fn (WinnerAuditLog $log): string => match ($log->actor_type) {
                                'operator' => $log->actor_id ? "Admin #{$log->actor_id}" : 'Operator (unattributed)',
                                'customer' => 'Customer',
                                default => Str::headline((string) $log->actor_type),
                            }),
                        TextEntry::make('created_at')->label('At')->dateTime(),
                    ]),
            ]);
    }
}
