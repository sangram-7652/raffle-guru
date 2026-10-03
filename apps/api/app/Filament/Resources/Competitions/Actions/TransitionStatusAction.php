<?php

namespace App\Filament\Resources\Competitions\Actions;

use App\Enums\CompetitionStatus;
use App\Filament\Support\CompetitionStatusBadge;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Draw;
use App\Services\Competitions\CompetitionLifecycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The ONLY place in the admin panel that can change
 * `competitions.status` (P11.5 §11/§12) — and even this never writes it.
 * It calls CompetitionLifecycleService::transition(), which locks the
 * row, re-reads the CURRENT status (never trusting what this page
 * rendered), re-validates against its own matrix and writes the
 * CompetitionLifecycleEvent audit row. Which moves are legal comes only
 * from the service's canTransition() — there is no second matrix here.
 *
 * MANUALLY_REACHABLE narrows that further; it never widens it.
 * draw_in_progress, drawn and completed are produced exclusively as
 * side effects of DrawService running a real draw and
 * PrizeFulfilmentService completing fulfilment — forcing status into one
 * of them without the DrawResult/Winner rows those processes create
 * would break every downstream assumption. closed -> draw_pending IS
 * offered: reaching it is how App\Listeners\DispatchDrawExecution
 * enqueues the real draw, so it is the legitimate way for an admin to
 * start a draw early, and additionally requires DrawPolicy::run.
 * `draft` has exactly one outgoing edge: draft -> scheduled (P11.9), the
 * editorial publish. It is not public until scheduled -> active.
 */
class TransitionStatusAction
{
    /**
     * @var list<CompetitionStatus>
     */
    private const MANUALLY_REACHABLE = [
        CompetitionStatus::Scheduled,
        CompetitionStatus::Active,
        CompetitionStatus::SoldOut,
        CompetitionStatus::Closed,
        CompetitionStatus::DrawPending,
    ];

    public static function make(): Action
    {
        return Action::make('transitionStatus')
            ->label('Transition status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            // Custom actions have no automatic policy check — authorize
            // explicitly. Checked again by Filament on mount AND on call.
            ->authorize('transitionLifecycle')
            ->visible(fn (Competition $record): bool => ! $record->trashed() && self::targetOptions($record) !== [])
            ->modalHeading('Transition competition status')
            ->modalDescription('Moves the competition through CompetitionLifecycleService. The move is re-validated against the live status when you submit and recorded in the lifecycle audit log.')
            ->modalSubmitActionLabel('Transition')
            ->schema(fn (Competition $record): array => [
                TextEntry::make('current_status')
                    ->label('Current status')
                    ->state(CompetitionStatusBadge::label($record->status))
                    ->badge()
                    ->color(CompetitionStatusBadge::color($record->status)),
                Select::make('to_status')
                    ->label('Target status')
                    ->required()
                    ->options(fn (): array => self::targetOptions($record))
                    ->native(false),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Recorded in the audit log alongside your admin ID.')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500),
            ])
            ->action(function (Competition $record, array $data): void {
                $to = CompetitionStatus::tryFrom((string) ($data['to_status'] ?? ''));

                // UI filtering is not the security boundary: re-check the
                // target server-side against exactly what the form was
                // allowed to offer, so a crafted Livewire payload can't
                // request draw_in_progress/drawn/completed.
                if ($to === null || ! array_key_exists($to->value, self::targetOptions($record))) {
                    self::rejected();

                    return;
                }

                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();

                // The service re-locks the row and re-checks the CURRENT
                // status itself, so a status changed by the scheduler or
                // another admin since this modal opened is never blindly
                // overwritten (P11.5 §13). `false` is reported, never
                // swallowed as success.
                $transitioned = app(CompetitionLifecycleService::class)->transition(
                    $record,
                    $to,
                    "Admin #{$admin->getKey()}: ".trim($data['reason']),
                );

                $record->refresh();

                if (! $transitioned) {
                    self::rejected();

                    return;
                }

                Notification::make()
                    ->title('Status changed to '.CompetitionStatusBadge::label($to))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function targetOptions(Competition $record): array
    {
        $service = app(CompetitionLifecycleService::class);
        $admin = Auth::guard('admin')->user();
        $options = [];

        foreach (self::MANUALLY_REACHABLE as $target) {
            if (! $service->canTransition($record->status, $target)) {
                continue;
            }

            if ($target === CompetitionStatus::DrawPending && ! $admin?->can('run', Draw::class)) {
                continue;
            }

            $options[$target->value] = CompetitionStatusBadge::label($target);
        }

        return $options;
    }

    private static function rejected(): void
    {
        Notification::make()
            ->title('Transition rejected')
            ->body('The competition\'s status changed since this page loaded, or that transition is not allowed from its current status. Nothing was changed.')
            ->danger()
            ->send();
    }
}
