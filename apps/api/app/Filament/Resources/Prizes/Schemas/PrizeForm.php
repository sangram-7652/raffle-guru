<?php

namespace App\Filament\Resources\Prizes\Schemas;

use App\Filament\Support\CompetitionPreLaunch;
use App\Filament\Support\MinorUnitMoney;
use App\Models\Competition;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Only existing Prize columns. `currency` is display-only — pinned to
 * GBP server-side by CreatePrize / PrizesRelationManager and never
 * written on edit (same reasoning as CompetitionForm).
 */
class PrizeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Competition')
                ->components([self::competitionField()]),
            Section::make('Prize')
                ->columns(2)
                ->components(self::sharedFields()),
        ]);
    }

    /**
     * Everything except `competition_id` — PrizesRelationManager sets
     * that from its owner record server-side, so its form has no such
     * field to submit.
     *
     * @return array<int, Component>
     */
    public static function sharedFields(): array
    {
        return [
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Repeater::make('description')
                ->label('Description (bullet points)')
                ->simple(
                    TextInput::make('point')->required()->maxLength(500),
                )
                ->addActionLabel('Add bullet point')
                // Repeater defaults to one (required) item on mount.
                ->defaultItems(0)
                ->columnSpanFull(),
            TextInput::make('value')
                ->label('Value')
                ->required()
                ->numeric()
                ->minValue(0)
                ->maxValue(MinorUnitMoney::MAX_MAJOR_UNITS)
                ->rules(MinorUnitMoney::RULES)
                ->step(0.01)
                ->prefix('£')
                ->formatStateUsing(fn (?int $state): ?string => MinorUnitMoney::toMajorUnits($state))
                ->dehydrateStateUsing(fn ($state): ?int => MinorUnitMoney::toMinorUnits($state))
                ->helperText('Pounds and pence. Stored in pence.'),
            TextInput::make('cash_alternative_value')
                ->label('Cash alternative')
                ->numeric()
                ->minValue(0)
                ->maxValue(MinorUnitMoney::MAX_MAJOR_UNITS)
                ->rules(MinorUnitMoney::RULES)
                ->step(0.01)
                ->prefix('£')
                ->nullable()
                ->formatStateUsing(fn (?int $state): ?string => MinorUnitMoney::toMajorUnits($state))
                ->dehydrateStateUsing(fn ($state): ?int => MinorUnitMoney::toMinorUnits($state))
                ->helperText('Leave blank if there is no cash alternative.'),
            Select::make('currency')
                ->options(['GBP' => 'GBP (£)'])
                ->default('GBP')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('quantity')
                ->required()
                ->integer()
                ->minValue(1)
                ->maxValue(4_294_967_295)
                ->default(1),
        ];
    }

    /**
     * Chosen once, at create, from pre-launch competitions only (see
     * CompetitionPreLaunch for why the prize set is frozen once a
     * competition has been live). On edit it's disabled — and disabled
     * fields are never dehydrated — because the domain has no "move a
     * prize to another competition" concept.
     *
     * The explicit rule re-checks the submitted ID in the database, so a
     * crafted Livewire request can't attach a prize to a live, trashed
     * or non-existent competition regardless of the options shown.
     * Searchable without preload: options are fetched 50 at a time.
     */
    private static function competitionField(): Select
    {
        return Select::make('competition_id')
            ->label('Competition')
            ->relationship(
                'competition',
                'title',
                // Filter on create only: on edit the (disabled) field must
                // still resolve a prize's existing, possibly live, parent.
                modifyQueryUsing: fn (Builder $query, string $operation): Builder => $operation === 'create'
                    ? $query->whereIn('status', CompetitionPreLaunch::statuses())
                    : $query,
            )
            ->searchable(['title', 'slug'])
            ->required()
            ->disabledOn('edit')
            ->helperText(fn (string $operation): string => $operation === 'create'
                ? 'Only draft or scheduled competitions can receive new prizes.'
                : 'A prize cannot be moved to a different competition.')
            ->rules([
                fn (string $operation): Closure => function (string $attribute, $value, Closure $fail) use ($operation): void {
                    if ($operation !== 'create') {
                        return;
                    }

                    $competition = is_numeric($value) ? Competition::query()->find($value) : null;

                    if (! CompetitionPreLaunch::is($competition)) {
                        $fail('Choose an existing draft or scheduled competition.');
                    }
                },
            ]);
    }
}
