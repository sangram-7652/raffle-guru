<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Winners\WinnerResource;
use App\Filament\Support\PrizeDeliveryStatusBadge;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Filament\Support\WinnerStatusBadge;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use App\Models\WinnerClaim;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The customer's wins, with claim and fulfilment status alongside.
 *
 * This is a view only. Every action on a winner, claim or delivery
 * (contact, verify, approve, reject, fulfilment progression, prize
 * assignment) belongs to WinnerResource, WinnerClaimResource or the
 * services behind them, so each row links there and nothing is
 * duplicated here. The claim and delivery columns appear only for
 * admins who hold claim.view / fulfilment.view, mirroring the policies
 * that guard those resources.
 */
class WinnersRelationManager extends RelationManager
{
    protected static string $relationship = 'winners';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Winners & claims';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', Winner::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Winner')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->weight('medium')
                    ->url(fn (Winner $record): string => WinnerResource::getUrl('view', ['record' => $record])),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Winner $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap(),
                TextColumn::make('ticket.ticket_number')
                    ->label('Winning ticket no.')
                    ->url(fn (Winner $record): string => TicketResource::getUrl('view', ['record' => $record->ticket_id])),
                TextColumn::make('delivery.prize.title')
                    ->label('Prize')
                    ->placeholder('Not resolved yet')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Winner status')
                    ->badge()
                    ->formatStateUsing(fn (WinnerStatus $state): string => WinnerStatusBadge::label($state))
                    ->color(fn (WinnerStatus $state): string => WinnerStatusBadge::color($state)),
                TextColumn::make('claim.status')
                    ->label('Claim')
                    ->badge()
                    ->formatStateUsing(fn (?WinnerClaimStatus $state): ?string => WinnerClaimStatusBadge::label($state))
                    ->color(fn (?WinnerClaimStatus $state): string => WinnerClaimStatusBadge::color($state))
                    ->placeholder('No claim')
                    ->visible(fn (): bool => Gate::allows('viewAny', WinnerClaim::class)),
                TextColumn::make('delivery.status')
                    ->label('Delivery')
                    ->badge()
                    ->formatStateUsing(fn ($state): ?string => PrizeDeliveryStatusBadge::label($state))
                    ->color(fn ($state): string => PrizeDeliveryStatusBadge::color($state))
                    ->placeholder('No delivery yet')
                    ->visible(fn (): bool => Gate::allows('viewAny', PrizeDelivery::class)),
                TextColumn::make('created_at')
                    ->label('Won')
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'status']),
                'ticket' => fn ($q) => $q->select(['id', 'ticket_number', 'order_id']),
                'claim' => fn ($q) => $q->select(['id', 'winner_id', 'status', 'submitted_at']),
                'delivery' => fn ($q) => $q->select(['id', 'winner_id', 'prize_id', 'status']),
                'delivery.prize' => fn ($q) => $q->withTrashed()->select(['id', 'title']),
            ]))
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Winner $record): string => WinnerResource::getUrl('view', ['record' => $record]));
    }
}
