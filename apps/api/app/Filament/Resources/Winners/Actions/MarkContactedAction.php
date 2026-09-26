<?php

namespace App\Filament\Resources\Winners\Actions;

use App\Enums\WinnerStatus;
use App\Models\Admin;
use App\Models\Winner;
use App\Services\Winner\WinnerLifecycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The one winner transition an operator performs directly in P10:
 * pending_contact -> contacted (docs/WINNER_CLAIM_P10.md — nothing else
 * in the codebase performs it). Records THAT the winner was contacted;
 * it sends nothing (P10 has no notification delivery — §17).
 *
 * Every other winner transition is owned elsewhere and deliberately not
 * offered here: -> verification_pending happens when the customer
 * submits a claim; -> verified / claim_approved are side effects of
 * claim review (WinnerClaimService, see WinnerClaimResource);
 * -> delivery_pending / delivered / completed belong to
 * PrizeFulfilmentService (P11.10).
 *
 * WinnerLifecycleService owns the matrix and re-checks it under a row
 * lock; this action only asks canTransition() for availability.
 */
class MarkContactedAction
{
    public static function make(): Action
    {
        return Action::make('markContacted')
            ->label('Mark contacted')
            ->icon(Heroicon::OutlinedPhone)
            ->color('primary')
            ->authorize('transition') // WinnerPolicy::transition (winner.transition)
            ->visible(fn (Winner $record): bool => app(WinnerLifecycleService::class)
                ->canTransition($record->status, WinnerStatus::Contacted))
            ->modalHeading('Mark winner as contacted')
            ->modalDescription('Records that the winner has been contacted so they can submit their claim. This does not send any message.')
            ->modalSubmitActionLabel('Mark contacted')
            ->schema([
                Textarea::make('note')
                    ->label('How were they contacted?')
                    ->helperText('Recorded in the winner audit log with your admin ID.')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500),
            ])
            ->action(function (Winner $record, array $data): void {
                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();

                $transitioned = app(WinnerLifecycleService::class)->transition(
                    $record,
                    WinnerStatus::Contacted,
                    trim($data['note']),
                    actorType: 'operator',
                    actorId: $admin->getKey(),
                );

                $record->refresh();

                if ($transitioned) {
                    Notification::make()->title('Winner marked as contacted')->success()->send();

                    return;
                }

                Notification::make()
                    ->title('Not changed')
                    ->body('The winner\'s status changed since this page loaded, so this step no longer applies. Nothing was changed.')
                    ->warning()
                    ->send();
            });
    }
}
