<?php

namespace App\Filament\Resources\Draws;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Draws\Pages\ListDraws;
use App\Filament\Resources\Draws\Pages\ViewDraw;
use App\Filament\Resources\Draws\Schemas\DrawInfolist;
use App\Filament\Resources\Draws\Tables\DrawsTable;
use App\Models\Draw;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * P11.8 — P9 draw ATTEMPTS. A `draws` row is created by DrawService only
 * once a draw starts (running -> completed | failed; a crashed attempt is
 * recovered by a NEW attempt row), so every record here is history and
 * the resource is otherwise read-only (ReadOnlyResource: no create/edit/
 * delete/restore/replicate, no bulk actions). Viewing is gated by
 * DrawPolicy (draw.view).
 *
 * The one operational action is "Run draw" on the list page
 * (Actions\RunDrawAction): it asks DrawService::run() to draw a
 * competition that is awaiting one, and nothing else. The admin never
 * chooses a ticket, a winner or any randomness — DrawService, its
 * eligibility snapshot and CSPRNG strategy decide everything.
 */
class DrawResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Draw::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Tickets & Draws';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return DrawInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DrawsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDraws::route('/'),
            'view' => ViewDraw::route('/{record}'),
        ];
    }

    /**
     * Only what the list shows: the competition's title/slug and the
     * result's winning ticket reference — never the competition's
     * tickets, entries or orders.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'status', 'draw_at']),
            'result' => fn ($q) => $q->select(['id', 'draw_id', 'ticket_id', 'ticket_number', 'eligible_ticket_count', 'selected_at']),
        ]);
    }
}
