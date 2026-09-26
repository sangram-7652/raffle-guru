<?php

namespace App\Filament\Resources\Competitions\Schemas;

use App\Filament\Support\MinorUnitMoney;
use App\Models\Competition;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lifecycle status is DELIBERATELY absent from this entire form — a
 * hard architectural rule (P11.5 §11). The only way an admin changes it
 * is the "Transition Status" action on the view page
 * (Actions\TransitionStatusAction), which goes through
 * CompetitionLifecycleService. `currency` is display-only here and
 * pinned server-side by the Create/Edit pages (GBP only — see below).
 *
 * Every rule below runs server-side in the Livewire request (Filament
 * validates in PHP before dehydrating), not just in the browser.
 */
class CompetitionForm
{
    /**
     * Lowercase words joined by single hyphens — what Str::slug()
     * produces and what the frontend's /competitions/{slug} routes expect.
     */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * unsignedInteger column ceiling for max_entries / max_entries_per_user.
     */
    private const MAX_UNSIGNED_INT = 4_294_967_295;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::identitySection(),
                self::pricingSection(),
                self::scheduleSection(),
                self::skillQuestionSection(),
                self::imagesSection(),
            ]);
    }

    private static function identitySection(): Section
    {
        return Section::make('Identity')
            ->columns(2)
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    // Auto-fill the slug only on create, and only while
                    // the slug is still blank or still the auto-generated
                    // one — never over a slug the admin typed, and never
                    // on edit (P11.5 §6: an existing competition's slug is
                    // its public URL).
                    ->afterStateUpdated(function (string $operation, Get $get, Set $set, ?string $old, ?string $state): void {
                        if ($operation !== 'create') {
                            return;
                        }

                        $slug = $get('slug');

                        if (blank($slug) || $slug === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->regex(self::SLUG_PATTERN)
                    ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens only.'])
                    // Laravel's unique rule queries the table directly, so
                    // soft-deleted competitions' slugs count too — matching
                    // the database's own unique index.
                    ->unique(ignoreRecord: true)
                    ->helperText('The public URL. Changing it on a live competition breaks existing links.'),
                TextInput::make('short_description')
                    ->label('Short description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Repeater::make('description')
                    ->label('Description (paragraphs)')
                    ->simple(
                        TextInput::make('paragraph')->required()->maxLength(5000),
                    )
                    ->addActionLabel('Add paragraph')
                    // Repeater defaults to one item on mount — description
                    // is nullable, so a blank form starts with zero.
                    ->defaultItems(0)
                    ->columnSpanFull(),
                // Free-text, as the column is (a plain indexed string —
                // no categories table exists; see docs/SCHEMA_DESIGN_P2.md).
                // Existing values are offered as suggestions.
                TextInput::make('category')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Competition::query()
                        ->withTrashed()
                        ->distinct()
                        ->orderBy('category')
                        ->limit(100)
                        ->pluck('category')
                        ->all()),
                Toggle::make('is_featured')
                    ->label('Featured')
                    ->inline(false),
            ]);
    }

    private static function pricingSection(): Section
    {
        return Section::make('Pricing & capacity')
            ->columns(2)
            ->components([
                TextInput::make('entry_price')
                    ->label('Entry price')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(MinorUnitMoney::MAX_MAJOR_UNITS)
                    ->rules(MinorUnitMoney::RULES)
                    ->step(0.01)
                    ->prefix('£')
                    ->formatStateUsing(fn (?int $state): ?string => MinorUnitMoney::toMajorUnits($state))
                    ->dehydrateStateUsing(fn ($state): ?int => MinorUnitMoney::toMinorUnits($state))
                    ->helperText('Pounds and pence, e.g. 10.50. Stored in pence.'),
                // Display only. The domain is GBP-only (every migration
                // defaults currency to GBP; nothing converts or charges
                // any other currency), so the Create page pins it and the
                // Edit page never writes it — no client-submitted value
                // is ever persisted.
                Select::make('currency')
                    ->options(['GBP' => 'GBP (£)'])
                    ->default('GBP')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('max_entries')
                    ->label('Max entries')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(self::MAX_UNSIGNED_INT)
                    // Data-integrity floor only: never let an edit set the
                    // cap below tickets that already exist, which would
                    // leave the competition permanently over capacity.
                    // Actual allocation/capacity enforcement stays in
                    // TicketAllocationService.
                    ->rules([
                        fn (?Competition $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                            if ($record === null) {
                                return;
                            }

                            $allocated = $record->tickets()->count();

                            if ((int) $value < $allocated) {
                                $fail("Max entries cannot be lower than the {$allocated} tickets already allocated.");
                            }
                        },
                    ]),
                TextInput::make('max_entries_per_user')
                    ->label('Max entries per user')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(self::MAX_UNSIGNED_INT)
                    ->nullable()
                    ->helperText('Leave blank for no per-user limit.')
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                            $maxEntries = $get('max_entries');

                            if (filled($maxEntries) && is_numeric($maxEntries) && (int) $value > (int) $maxEntries) {
                                $fail('The per-user limit cannot exceed max entries.');
                            }
                        },
                    ]),
            ]);
    }

    /**
     * All three are nullable, matching the columns and
     * CompetitionLifecycleCommand (a null starts_at/ends_at/draw_at just
     * means that automatic transition never fires). When both sides of a
     * comparison are set: ends_at must be after starts_at, and draw_at
     * cannot precede the entry window. Stored and shown in the app's
     * configured timezone (config('app.timezone')) — nothing here
     * overrides it.
     */
    private static function scheduleSection(): Section
    {
        return Section::make('Schedule')
            ->description('Drives the automatic lifecycle: scheduled → active at "Starts at", active → closed at "Ends at", closed → draw pending at "Draw at". Leave blank to transition manually.')
            ->columns(3)
            ->components([
                DateTimePicker::make('starts_at')
                    ->label('Starts at')
                    ->seconds(false),
                DateTimePicker::make('ends_at')
                    ->label('Ends at')
                    ->seconds(false)
                    ->rules([
                        fn (Get $get): Closure => self::notBefore($get('starts_at'), 'Ends at must be after starts at.', strict: true),
                    ]),
                DateTimePicker::make('draw_at')
                    ->label('Draw at')
                    ->seconds(false)
                    ->rules([
                        fn (Get $get): Closure => self::notBefore($get('ends_at') ?: $get('starts_at'), 'Draw at cannot be before the entry window ends.'),
                    ]),
            ]);
    }

    /**
     * Admin-only configuration. The public API
     * (App\Http\Resources\CompetitionResource, unchanged by P11.5)
     * strips `answer_index`; nothing under app/Filament logs or echoes
     * it outside the authenticated panel.
     *
     * `answer_index` is a 0-based position in `options`, as the factory
     * and the public resource treat it. Options are therefore not
     * reorderable — reordering would silently change which option is
     * correct.
     */
    private static function skillQuestionSection(): Section
    {
        return Section::make('Skill question')
            ->description('Leave every field blank for a free-entry competition with no skill question.')
            ->collapsible()
            ->components([
                TextInput::make('skill_question.prompt')
                    ->label('Prompt')
                    ->maxLength(500)
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => self::optionList($get('skill_question.options')) !== []),
                Repeater::make('skill_question.options')
                    ->label('Options')
                    ->simple(
                        TextInput::make('option')->required()->maxLength(255),
                    )
                    ->addActionLabel('Add option')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->maxItems(10)
                    ->minItems(fn (Get $get): int => filled($get('skill_question.prompt')) ? 2 : 0)
                    ->live(),
                Select::make('skill_question.answer_index')
                    ->label('Correct answer')
                    ->options(fn (Get $get): array => collect(self::optionList($get('skill_question.options')))
                        ->mapWithKeys(fn (string $option, int $index): array => [$index => 'Option '.($index + 1).': '.$option])
                        ->all())
                    ->required(fn (Get $get): bool => filled($get('skill_question.prompt')))
                    ->helperText('Never shown to customers — only the prompt and option text are public.'),
            ]);
    }

    private static function imagesSection(): Section
    {
        return Section::make('Images')
            ->description('Existing convention: an ordered list of image URLs. The first is the hero image.')
            ->collapsible()
            ->components([
                Repeater::make('images')
                    ->hiddenLabel()
                    ->simple(
                        TextInput::make('url')
                            ->required()
                            ->url()
                            ->startsWith(['https://'])
                            ->maxLength(2048),
                    )
                    ->addActionLabel('Add image URL')
                    ->defaultItems(0),
            ]);
    }

    /**
     * Repeater state is keyed by per-item UUIDs while the form is live;
     * answer_index is positional, so always re-index. Blank entries are
     * dropped so an empty new row never becomes a selectable answer.
     *
     * @return list<string>
     */
    private static function optionList(mixed $options): array
    {
        return collect(is_array($options) ? $options : [])
            ->map(fn ($option) => is_array($option) ? reset($option) : $option)
            ->filter(fn ($option) => is_string($option) && filled($option))
            ->values()
            ->all();
    }

    private static function notBefore(mixed $other, string $message, bool $strict = false): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($other, $message, $strict): void {
            if (blank($other) || blank($value)) {
                return;
            }

            $value = Carbon::parse($value);
            $other = Carbon::parse($other);

            if ($strict ? $value->lessThanOrEqualTo($other) : $value->lessThan($other)) {
                $fail($message);
            }
        };
    }
}
