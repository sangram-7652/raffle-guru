<?php

namespace App\Filament\Resources\Winners\Schemas;

use App\Enums\AdminAbility;
use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Draws\DrawResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\WinnerClaims\WinnerClaimResource;
use App\Filament\Support\PrizeDeliveryStatusBadge;
use App\Filament\Support\WinnerAuditTrail;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Filament\Support\WinnerStatusBadge;
use App\Models\Admin;
use App\Models\Winner;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only. Identity comes from the draw (competition, draw, winning
 * ticket, its owner) exactly as WinnerService recorded it — nothing is
 * recalculated. The prize is the P10 delivery row's (resolved by
 * PrizeFulfilmentService at fulfilment); winner/claim `metadata` is
 * never shown.
 */
class WinnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Winner')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('id')
                            ->label('Winner')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->weight('medium'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (WinnerStatus $state): string => WinnerStatusBadge::label($state))
                            ->color(fn (WinnerStatus $state): string => WinnerStatusBadge::color($state)),
                        TextEntry::make('created_at')->label('Won')->dateTime(),
                        TextEntry::make('competition.title')
                            ->label('Competition')
                            ->url(fn (Winner $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                            ->columnSpan(2),
                        TextEntry::make('draw_id')
                            ->label('Draw')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->url(fn (Winner $record): string => DrawResource::getUrl('view', ['record' => $record->draw_id])),
                        TextEntry::make('ticket.ticket_number')
                            ->label('Winning ticket no.')
                            ->weight('bold')
                            ->url(fn (Winner $record): string => TicketResource::getUrl('view', ['record' => $record->ticket_id])),
                        TextEntry::make('order_reference')
                            ->label('Order')
                            ->state(fn (Winner $record): ?string => $record->ticket?->order()->withTrashed()->value('reference'))
                            ->url(fn (Winner $record): ?string => $record->ticket
                                ? OrderResource::getUrl('view', ['record' => $record->ticket->order_id])
                                : null),
                        TextEntry::make('delivery.prize.title')
                            ->label('Prize')
                            ->placeholder('Not resolved yet — assigned at fulfilment'),
                    ]),

                Section::make('Customer')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('user.name')->label('Name'),
                        TextEntry::make('user.email')->label('Email')->copyable(),
                    ]),

                Section::make('Claim')
                    ->columnSpanFull()
                    ->columns(3)
                    ->components([
                        TextEntry::make('claim.status')
                            ->label('Claim status')
                            ->badge()
                            ->formatStateUsing(fn (?WinnerClaimStatus $state): ?string => WinnerClaimStatusBadge::label($state))
                            ->color(fn (?WinnerClaimStatus $state): string => WinnerClaimStatusBadge::color($state))
                            ->placeholder('No claim submitted yet')
                            ->url(fn (Winner $record): ?string => $record->claim
                                ? WinnerClaimResource::getUrl('view', ['record' => $record->claim])
                                : null),
                        TextEntry::make('claim.submitted_at')->label('Submitted')->dateTime()->placeholder('—'),
                    ]),

                Section::make('Lifecycle')
                    ->columnSpanFull()
                    ->columns(6)
                    ->collapsible()
                    ->components([
                        TextEntry::make('contacted_at')->label('Contacted')->dateTime()->placeholder('—'),
                        TextEntry::make('verified_at')->label('Verified')->dateTime()->placeholder('—'),
                        TextEntry::make('claim_approved_at')->label('Claim approved')->dateTime()->placeholder('—'),
                        TextEntry::make('delivery_pending_at')->label('Delivery pending')->dateTime()->placeholder('—'),
                        TextEntry::make('delivered_at')->label('Delivered')->dateTime()->placeholder('—'),
                        TextEntry::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
                    ]),

                Section::make('Fulfilment')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (): bool => self::canViewFulfilment())
                    ->components([
                        TextEntry::make('delivery.status')
                            ->label('Delivery status')
                            ->badge()
                            ->formatStateUsing(fn ($state): ?string => PrizeDeliveryStatusBadge::label($state))
                            ->color(fn ($state): string => PrizeDeliveryStatusBadge::color($state))
                            ->placeholder('No delivery yet'),
                        TextEntry::make('delivery.delivery_method')->label('Delivery method')->placeholder('—'),
                        TextEntry::make('delivery.tracking_reference')->label('Tracking reference')->copyable()->placeholder('—'),
                        TextEntry::make('delivery.shipped_at')->label('Shipped')->dateTime()->placeholder('—'),
                        TextEntry::make('delivery.delivered_at')->label('Delivered (prize)')->dateTime()->placeholder('—'),
                        TextEntry::make('delivery.notes')->label('Internal notes')->placeholder('—')->columnSpanFull(),
                    ]),

                WinnerAuditTrail::section(fn (Winner $record): int => $record->id),
            ]);
    }

    /** Fulfilment details are operational data; gated on fulfilment.view. */
    private static function canViewFulfilment(): bool
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();

        return $admin !== null && $admin->hasAbility(AdminAbility::FulfilmentView);
    }
}
