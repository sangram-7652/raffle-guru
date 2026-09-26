<?php

namespace App\Filament\Resources\Prizes;

use App\Filament\Resources\Prizes\Pages\CreatePrize;
use App\Filament\Resources\Prizes\Pages\EditPrize;
use App\Filament\Resources\Prizes\Pages\ListPrizes;
use App\Filament\Resources\Prizes\Pages\ViewPrize;
use App\Filament\Resources\Prizes\Schemas\PrizeForm;
use App\Filament\Resources\Prizes\Schemas\PrizeInfolist;
use App\Filament\Resources\Prizes\Tables\PrizesTable;
use App\Filament\Support\CompetitionPreLaunch;
use App\Models\Prize;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Authorization is PrizePolicy throughout. A prize always belongs to
 * exactly one competition (FK, restrictOnDelete) — chosen at create and
 * never changed (see PrizeForm).
 *
 * Adding, soft-deleting and restoring prizes is allowed only while the
 * parent competition is pre-launch (see prizeSetIsEditable()):
 * PrizeResolutionService resolves a winner's prize only when the
 * competition has exactly one prize row, and WinnerResource reads
 * delivery->prize, so changing the prize set after launch changes what
 * customers bought and can break fulfilment. Editing an existing
 * prize's text/value stays allowed for corrections. No force-delete
 * anywhere (prize_deliveries.prize_id is restrictOnDelete()).
 */
class PrizeResource extends Resource
{
    protected static ?string $model = Prize::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Competitions';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PrizeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PrizeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrizesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrizes::route('/'),
            'create' => CreatePrize::route('/create'),
            'view' => ViewPrize::route('/{record}'),
            'edit' => EditPrize::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('competition');
    }

    /**
     * Whether the parent competition's prize set may still change (see
     * class docblock). Uses the eager-loaded relation (no per-row query);
     * it excludes soft-deleted competitions, so a prize under a deleted
     * competition resolves to null and is never actionable.
     */
    public static function prizeSetIsEditable(Prize $prize): bool
    {
        return CompetitionPreLaunch::is($prize->competition);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
