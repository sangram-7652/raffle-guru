<?php

namespace App\Filament\Resources\WinnerClaims\Schemas;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Winners\WinnerResource;
use App\Filament\Support\WinnerAuditTrail;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Filament\Support\WinnerStatusBadge;
use App\Models\WinnerClaim;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only. Shows what P10 intentionally stores on a claim: status,
 * its timestamps, the customer's own submission `notes`, and the
 * `rejection_reason` (which the customer also sees). Claim `metadata` is
 * never shown. P10 has no KYC data and none is fabricated here.
 */
class WinnerClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Claim')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('id')
                            ->label('Claim')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->weight('medium'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (WinnerClaimStatus $state): string => WinnerClaimStatusBadge::label($state))
                            ->color(fn (WinnerClaimStatus $state): string => WinnerClaimStatusBadge::color($state)),
                        TextEntry::make('submitted_at')->label('Submitted')->dateTime(),
                        TextEntry::make('verified_at')->label('Verified')->dateTime()->placeholder('—'),
                        TextEntry::make('rejected_at')->label('Rejected')->dateTime()->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->label('Rejection reason (shown to the winner)')
                            ->visible(fn (WinnerClaim $record): bool => $record->status === WinnerClaimStatus::Rejected)
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Winner\'s notes')
                            ->placeholder('None provided')
                            ->columnSpanFull(),
                    ]),

                Section::make('Winner')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('winner_id')
                            ->label('Winner')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->url(fn (WinnerClaim $record): string => WinnerResource::getUrl('view', ['record' => $record->winner_id])),
                        TextEntry::make('winner.status')
                            ->label('Winner status')
                            ->badge()
                            ->formatStateUsing(fn (WinnerStatus $state): string => WinnerStatusBadge::label($state))
                            ->color(fn (WinnerStatus $state): string => WinnerStatusBadge::color($state)),
                        TextEntry::make('winner.user.name')->label('Customer'),
                        TextEntry::make('winner.user.email')->label('Email')->copyable(),
                        TextEntry::make('winner.competition.title')
                            ->label('Competition')
                            ->url(fn (WinnerClaim $record): ?string => $record->winner
                                ? CompetitionResource::getUrl('view', ['record' => $record->winner->competition_id])
                                : null),
                        TextEntry::make('winner.ticket.ticket_number')
                            ->label('Winning ticket no.')
                            ->url(fn (WinnerClaim $record): ?string => $record->winner
                                ? TicketResource::getUrl('view', ['record' => $record->winner->ticket_id])
                                : null),
                    ]),

                WinnerAuditTrail::section(fn (WinnerClaim $record): int => $record->winner_id),
            ]);
    }
}
