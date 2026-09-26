<?php

namespace App\Filament\Resources\WinnerClaims;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\WinnerClaims\Pages\ListWinnerClaims;
use App\Filament\Resources\WinnerClaims\Pages\ViewWinnerClaim;
use App\Filament\Resources\WinnerClaims\Schemas\WinnerClaimInfolist;
use App\Filament\Resources\WinnerClaims\Tables\WinnerClaimsTable;
use App\Models\WinnerClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * P11.9 — P10 winner claims. A claim is created only by the winner
 * (WinnerClaimService::submit() via their account API), so there is no
 * create/edit/delete here (ReadOnlyResource) and no form. Viewing is
 * gated by WinnerClaimPolicy (claim.view); the review actions
 * (Actions\ClaimTransitionAction) go through WinnerClaimService and are
 * each gated by their own policy method.
 */
class WinnerClaimResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = WinnerClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Winners';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Claims';

    protected static ?string $modelLabel = 'claim';

    public static function infolist(Schema $schema): Schema
    {
        return WinnerClaimInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WinnerClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWinnerClaims::route('/'),
            'view' => ViewWinnerClaim::route('/{record}'),
        ];
    }

    /**
     * Minimal projections through the winner; customer credentials and
     * claim/winner metadata are never selected.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'winner' => fn ($q) => $q->select(['id', 'competition_id', 'ticket_id', 'user_id', 'status']),
            'winner.user' => fn ($q) => $q->select(['id', 'name', 'email']),
            'winner.competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug']),
            'winner.ticket' => fn ($q) => $q->select(['id', 'ticket_number']),
        ]);
    }
}
