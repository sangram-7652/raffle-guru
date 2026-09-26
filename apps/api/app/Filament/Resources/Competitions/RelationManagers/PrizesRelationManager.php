<?php

namespace App\Filament\Resources\Competitions\RelationManagers;

use App\Filament\Resources\Prizes\Schemas\PrizeForm;
use App\Filament\Support\CompetitionPreLaunch;
use App\Models\Competition;
use App\Models\Prize;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Prizes of one competition. Read-only on the View page (Filament's
 * default for relation managers there), manageable from Edit. Every
 * action is still authorized per PrizePolicy by Filament.
 *
 * A prize created here always gets the owner competition's id,
 * server-side, from the relationship — the form (PrizeForm::sharedFields())
 * has no competition_id field to submit. No Associate/Dissociate: the
 * domain has no concept of moving a prize between competitions. Adding,
 * deleting and restoring prizes is limited to pre-launch competitions —
 * see PrizeResource's docblock. No bulk actions, no force-delete.
 */
class PrizesRelationManager extends RelationManager
{
    protected static string $relationship = 'prizes';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(PrizeForm::sharedFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->description(fn (): ?string => $this->prizeSetIsEditable()
                ? null
                : 'This competition has launched, so prizes can no longer be added or removed. Existing prizes can still be corrected.')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('value')
                    ->money('GBP', divideBy: 100),
                TextColumn::make('cash_alternative_value')
                    ->label('Cash alternative')
                    ->money('GBP', divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('quantity')
                    ->numeric(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->prizeSetIsEditable())
                    // GBP only — see PrizeForm.
                    ->mutateDataUsing(function (array $data): array {
                        $data['currency'] = 'GBP';

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        unset($data['currency'], $data['competition_id']);

                        return $data;
                    }),
                ActionGroup::make([
                    DeleteAction::make()
                        ->visible(fn (): bool => $this->prizeSetIsEditable()),
                    RestoreAction::make()
                        ->visible(fn (Prize $record): bool => $record->trashed() && $this->prizeSetIsEditable()),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]));
    }

    private function prizeSetIsEditable(): bool
    {
        /** @var Competition $competition */
        $competition = $this->getOwnerRecord();

        return CompetitionPreLaunch::is($competition);
    }
}
