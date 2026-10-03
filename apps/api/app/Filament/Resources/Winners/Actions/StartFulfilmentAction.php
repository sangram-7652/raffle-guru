<?php

namespace App\Filament\Resources\Winners\Actions;

use App\Models\Admin;
use App\Models\Competition;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use App\Services\Winner\PrizeFulfilmentService;
use App\Services\Winner\PrizeResolutionException;
use App\Services\Winner\WinnerException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Starts fulfilment for a winner whose claim is approved: one call into
 * PrizeFulfilmentService::startFulfilment(), which moves the winner to
 * delivery_pending and creates the delivery atomically.
 *
 * A competition with several prizes needs an explicit choice: the list
 * is the service's assignablePrizes(), never built here. A single-prize
 * competition is resolved automatically and asks nothing extra.
 *
 * Authorization is server-side: PrizeDeliveryPolicy::start (and ::assignPrize
 * when a choice is required) is checked again inside the action, so a crafted
 * request cannot skip the visibility rule.
 */
class StartFulfilmentAction
{
    public static function make(): Action
    {
        return Action::make('startFulfilment')
            ->label('Start fulfilment')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->visible(fn (Winner $record): bool => self::isPermitted()
                && app(PrizeFulfilmentService::class)->canStartFulfilment($record))
            ->requiresConfirmation()
            ->modalHeading('Start fulfilment')
            ->modalDescription('Moves the winner to "delivery pending" and creates the prize delivery. Nothing is sent to the winner automatically.')
            ->modalSubmitActionLabel('Start fulfilment')
            ->schema(fn (Winner $record): array => array_values(array_filter([
                self::requiresChoice($record) ? self::prizeField($record) : null,
                Textarea::make('reason')
                    ->label('Internal note')
                    ->helperText('Recorded in the winner audit log with your admin ID. Not shown to the winner.')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500),
            ])))
            ->action(function (Winner $record, array $data): void {
                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();
                $service = app(PrizeFulfilmentService::class);

                Gate::forUser($admin)->authorize('start', PrizeDelivery::class);

                // Another admin may have started (or the claim changed) since this
                // page loaded. Say so plainly instead of reporting a no-op as success.
                $record->refresh();
                if (! $service->canStartFulfilment($record)) {
                    Notification::make()
                        ->title('Not started')
                        ->body('Fulfilment has already been started or the claim has changed. Nothing was changed.')
                        ->warning()
                        ->send();

                    return;
                }

                $prize = null;
                if (self::requiresChoice($record)) {
                    Gate::forUser($admin)->authorize('assignPrize', PrizeDelivery::class);
                    $prize = $service->assignablePrizes($record->competition)
                        ->firstWhere('id', (int) $data['prize_id']);
                    if ($prize === null) {
                        Notification::make()->title('Not started')->body('That prize is no longer available for this competition. Nothing was changed.')->danger()->send();

                        return;
                    }
                }

                try {
                    $service->startFulfilment($record, $admin->getKey(), trim($data['reason']), $prize);
                } catch (WinnerException|PrizeResolutionException $e) {
                    $record->refresh();
                    Notification::make()
                        ->title('Not started')
                        ->body($e->getMessage().' Nothing was changed.')
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();
                Notification::make()->title('Fulfilment started')->success()->send();
            });
    }

    /** Operations and Super Admin both hold these; checked again in the action. */
    private static function isPermitted(): bool
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();

        return $admin !== null && $admin->can('start', PrizeDelivery::class);
    }

    private static function requiresChoice(Winner $record): bool
    {
        return app(PrizeFulfilmentService::class)->prizeSelectionRequired($record->competition);
    }

    private static function prizeField(Winner $record): Select
    {
        $options = app(PrizeFulfilmentService::class)
            ->assignablePrizes($record->competition)
            ->mapWithKeys(fn ($prize): array => [
                $prize->id => $prize->title.' — £'.number_format($prize->value / 100, 2),
            ])
            ->all();

        return Select::make('prize_id')
            ->label('Prize to award')
            ->helperText('This competition has several prizes, so the prize must be chosen explicitly.')
            ->options($options)
            ->required()
            ->native(false)
            ->validationMessages(['in' => 'That prize is no longer available for this competition. Reload the page and try again.']);
    }
}
