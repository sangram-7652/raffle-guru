<?php

namespace App\Filament\Resources\Draws\Schemas;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\CompetitionStatusBadge;
use App\Filament\Support\DrawStatusBadge;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\Winner;
use App\Services\Draw\DrawException;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Read-only view of one draw attempt: which competition, what state,
 * when it was scheduled/started/finished, the winning ticket (from the
 * persisted DrawResult only), the P9 snapshot/randomness record, and —
 * for admins holding audit.view — the draw audit trail.
 *
 * Loads only this attempt's own rows (competition, result, winning
 * ticket's owner, its few audit rows) — never the competition's
 * tickets/entries/orders.
 */
class DrawInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Draw attempt')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('id')
                            ->label('Draw')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->weight('medium'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (DrawStatus $state): string => DrawStatusBadge::label($state))
                            ->color(fn (DrawStatus $state): string => DrawStatusBadge::color($state)),
                        TextEntry::make('attempt')->numeric(),
                        TextEntry::make('started_at')->label('Started')->dateTime(),
                        TextEntry::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
                        TextEntry::make('failed_at')->label('Failed')->dateTime()->placeholder('—'),
                        TextEntry::make('failure')
                            ->label('Failure reason')
                            ->state(fn (Draw $record): ?string => self::safeFailureReason($record))
                            ->visible(fn (Draw $record): bool => $record->status === DrawStatus::Failed)
                            ->columnSpanFull(),
                    ]),

                Section::make('Competition')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('competition.title')
                            ->label('Title')
                            ->url(fn (Draw $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id])),
                        TextEntry::make('competition.status')
                            ->label('Current status')
                            ->badge()
                            ->formatStateUsing(fn (CompetitionStatus $state): string => CompetitionStatusBadge::label($state))
                            ->color(fn (CompetitionStatus $state): string => CompetitionStatusBadge::color($state)),
                        TextEntry::make('competition.draw_at')
                            ->label('Scheduled draw time')
                            ->dateTime()
                            ->placeholder('Not scheduled'),
                    ]),

                Section::make('Result')
                    ->description('Selected by the draw engine. Read-only.')
                    ->columnSpanFull()
                    ->columns(4)
                    ->visible(fn (Draw $record): bool => $record->result !== null)
                    ->components([
                        TextEntry::make('result.ticket_number')
                            ->label('Winning ticket no.')
                            ->size('lg')
                            ->weight('bold')
                            ->url(fn (Draw $record): ?string => $record->result
                                ? TicketResource::getUrl('view', ['record' => $record->result->ticket_id])
                                : null),
                        TextEntry::make('result.ticket.user.name')
                            ->label('Ticket holder'),
                        TextEntry::make('result.selected_at')->label('Selected at')->dateTime(),
                        TextEntry::make('winner_record')
                            ->label('Winner record (P10)')
                            ->state(function (Draw $record): string {
                                $status = Winner::query()->where('draw_result_id', $record->result?->id)->value('status');

                                return $status === null
                                    ? 'Not created yet'
                                    : Str::headline($status instanceof WinnerStatus ? $status->value : (string) $status);
                            })
                            ->badge()
                            ->color('gray'),
                    ]),

                Section::make('Snapshot & randomness')
                    ->description('Recorded by the draw engine at selection time.')
                    ->columnSpanFull()
                    ->columns(3)
                    ->collapsible()
                    ->components([
                        TextEntry::make('snapshot_at')->label('Snapshot taken')->dateTime()->placeholder('—'),
                        TextEntry::make('eligible_ticket_count')->label('Eligible tickets')->numeric()->placeholder('—'),
                        TextEntry::make('eligibility_version')->placeholder('—'),
                        TextEntry::make('snapshot_checksum')->label('Snapshot checksum')->copyable()->placeholder('—')->columnSpan(2),
                        TextEntry::make('randomness_method')->placeholder('—'),
                    ]),

                Section::make('Audit trail')
                    ->columnSpanFull()
                    ->collapsible()
                    // Draw audit rows are gated by DrawAuditLogPolicy
                    // (audit.view) — not granted to Operations in P11.2.
                    ->visible(fn (): bool => (bool) Auth::guard('admin')->user()?->can('viewAny', DrawAuditLog::class))
                    ->components([
                        RepeatableEntry::make('audit')
                            ->hiddenLabel()
                            ->state(fn (Draw $record) => $record->auditLogs()->orderBy('created_at')->orderBy('id')->limit(50)->get())
                            ->columns(3)
                            ->schema([
                                TextEntry::make('event'),
                                TextEntry::make('created_at')->label('At')->dateTime(),
                                // Curated keys only — never raw metadata
                                // (a failure's metadata can carry an
                                // arbitrary exception message).
                                TextEntry::make('summary')
                                    ->label('Details')
                                    ->state(fn (DrawAuditLog $record): string => self::auditSummary($record))
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    /**
     * DrawService writes ANY exception's message into failure_reason, so
     * only show text that is provably domain-authored: a DrawException
     * (per the attempt's draw.failed audit row), or — when there is no
     * draw.failed row — one of DrawService's own fixed lease/lost-race
     * strings. Anything else (e.g. a database error) is withheld.
     */
    private static function safeFailureReason(Draw $draw): ?string
    {
        $failedEvent = $draw->auditLogs()->where('event', 'draw.failed')->latest('id')->first();

        if ($failedEvent === null) {
            return $draw->failure_reason;
        }

        $exception = $failedEvent->metadata['exception'] ?? null;

        return is_string($exception) && is_a($exception, DrawException::class, true)
            ? $draw->failure_reason
            : 'Unexpected error — details are in the application logs.';
    }

    private static function auditSummary(DrawAuditLog $log): string
    {
        $metadata = $log->metadata ?? [];
        $parts = [];

        foreach (['attempt' => 'attempt', 'ticket_number' => 'ticket', 'eligible_ticket_count' => 'eligible', 'stale_attempt' => 'stale attempt', 'recovered_from_draw_id' => 'recovered from draw'] as $key => $label) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $parts[] = "{$label}: {$metadata[$key]}";
            }
        }

        if (isset($metadata['exception']) && is_string($metadata['exception'])) {
            $parts[] = 'exception: '.class_basename($metadata['exception']);
        }

        return implode(' · ', $parts);
    }
}
