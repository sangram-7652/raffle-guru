<?php

namespace App\Filament\Resources\Winners;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Winners\Pages\ListWinners;
use App\Filament\Resources\Winners\Pages\ViewWinner;
use App\Filament\Resources\Winners\Schemas\WinnerInfolist;
use App\Filament\Resources\Winners\Tables\WinnersTable;
use App\Models\Winner;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * P11.9 — P10 winners. A winner is created only by WinnerService from
 * the draw result (DrawResult -> winning ticket -> its owner), so its
 * identity (competition, draw, ticket, user) is never editable here:
 * ReadOnlyResource disables create/edit/delete/restore/replicate and
 * there is no form. Viewing is gated by WinnerPolicy (winner.view).
 *
 * The only mutation is the "Mark contacted" action (see
 * Actions\MarkContactedAction), which goes through
 * WinnerLifecycleService. The prize is shown from the P10 delivery row
 * (winners have no prize column; PrizeFulfilmentService resolves it) —
 * assigning or changing it is P11.10's.
 */
class WinnerResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Winner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Winners';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return WinnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WinnersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWinners::route('/'),
            'view' => ViewWinner::route('/{record}'),
        ];
    }

    /**
     * Minimal projections, one query per relation per page; customer
     * credentials are never selected.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'user' => fn ($q) => $q->select(['id', 'name', 'email']),
            'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'status']),
            'ticket' => fn ($q) => $q->select(['id', 'ticket_number', 'order_id']),
            'claim' => fn ($q) => $q->select(['id', 'winner_id', 'status', 'submitted_at']),
            'delivery' => fn ($q) => $q->select(['id', 'winner_id', 'prize_id', 'status']),
            'delivery.prize' => fn ($q) => $q->withTrashed()->select(['id', 'title']),
        ]);
    }
}
