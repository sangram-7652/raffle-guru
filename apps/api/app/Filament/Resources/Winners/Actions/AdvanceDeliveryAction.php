<?php

namespace App\Filament\Resources\Winners\Actions;

use App\Enums\PrizeDeliveryStatus;
use App\Filament\Support\PrizeDeliveryStatusBadge;
use App\Models\Admin;
use App\Models\Winner;
use App\Services\Winner\PrizeFulfilmentService;
use App\Services\Winner\WinnerException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Moves an existing delivery along the P10 prize delivery lifecycle. The
 * choices offered are exactly PrizeFulfilmentService::allowedNextStatuses();
 * the service re-checks the move under a row lock and refuses a stale page.
 *
 * Shipping details entered here (delivery method, tracking reference) are
 * operator-recorded facts. Nothing is sent to a courier, and no tracking
 * number is generated.
 */
class AdvanceDeliveryAction
{
    public static function make(): Action
    {
        return Action::make('advanceDelivery')
            ->label('Update delivery')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('primary')
            ->visible(fn (Winner $record): bool => self::nextStatuses($record) !== [] && self::isPermitted($record))
            ->requiresConfirmation()
            ->modalHeading('Update prize delivery')
            ->modalDescription(fn (Winner $record): string => 'Currently "'.PrizeDeliveryStatusBadge::label($record->delivery->status).'". Moves are one step at a time. Marking delivered completes the winner and the competition. Failed is final in this version: there is no retry path.')
            ->modalSubmitActionLabel('Update delivery')
            ->schema(fn (Winner $record): array => [
                Select::make('to_status')
                    ->label('New status')
                    ->options(fn (): array => self::optionsFor($record))
                    ->required()
                    ->native(false)
                    ->live()
                    ->validationMessages(['in' => 'This delivery has moved on since the page loaded. Reload the page to see its current status.']),
                TextInput::make('delivery_method')
                    ->label('Delivery method')
                    ->helperText('Optional. Recorded as entered; no courier is contacted.')
                    ->maxLength(100)
                    ->visible(fn (Get $get): bool => in_array($get('to_status'), [
                        PrizeDeliveryStatus::Processing->value,
                        PrizeDeliveryStatus::Shipped->value,
                    ], true)),
                TextInput::make('tracking_reference')
                    ->label('Tracking reference')
                    ->helperText('Optional. Copied exactly as entered.')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('to_status') === PrizeDeliveryStatus::Shipped->value),
                Textarea::make('reason')
                    ->label('Internal note')
                    ->helperText('Recorded in the winner audit log with your admin ID. Not shown to the winner.')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500),
            ])
            ->action(function (Winner $record, array $data): void {
                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();
                $delivery = $record->delivery;

                Gate::forUser($admin)->authorize('advance', $delivery);

                try {
                    app(PrizeFulfilmentService::class)->advance(
                        $delivery,
                        PrizeDeliveryStatus::from($data['to_status']),
                        trim($data['reason']),
                        trackingReference: self::clean($data['tracking_reference'] ?? null),
                        deliveryMethod: self::clean($data['delivery_method'] ?? null),
                        actorId: $admin->getKey(),
                    );
                } catch (WinnerException $e) {
                    $record->refresh();
                    Notification::make()
                        ->title('Not updated')
                        ->body($e->getMessage().' The delivery may have moved since this page loaded. Nothing was changed.')
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();
                Notification::make()->title('Delivery updated')->success()->send();
            });
    }

    /** @return list<PrizeDeliveryStatus> */
    private static function nextStatuses(Winner $record): array
    {
        if ($record->delivery === null) {
            return [];
        }

        return app(PrizeFulfilmentService::class)->allowedNextStatuses($record->delivery->status);
    }

    /** @return array<string, string> */
    private static function optionsFor(Winner $record): array
    {
        return collect(self::nextStatuses($record))
            ->mapWithKeys(fn (PrizeDeliveryStatus $status): array => [
                $status->value => PrizeDeliveryStatusBadge::label($status),
            ])
            ->all();
    }

    private static function isPermitted(Winner $record): bool
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();

        return $admin !== null && $record->delivery !== null && $admin->can('advance', $record->delivery);
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
