<?php

namespace App\Filament\Resources\WinnerClaims\Actions;

use App\Enums\WinnerClaimStatus;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Models\Admin;
use App\Models\WinnerClaim;
use App\Services\Winner\WinnerClaimException;
use App\Services\Winner\WinnerClaimService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Claim review actions — each a thin call into
 * WinnerClaimService::transition(), the only writer of claim status:
 *
 *   Start verification  submitted -> verification_pending  (claim.verify)
 *   Verify              verification_pending -> verified    (claim.verify)
 *   Approve             verified -> approved                (claim.approve)
 *   Reject              verified -> rejected                (claim.reject)
 *
 * Availability comes from the service's own canTransition(), never a
 * copied matrix; the service re-checks under a row lock, performs the
 * coupled winner transition (verified -> winner verified, approved ->
 * winner claim_approved) itself, and writes the audit rows with this
 * admin's ID. A stale page (another admin already acted) surfaces the
 * service's own rejection message. Nothing here verifies identity or
 * calls any KYC service — P10 has none.
 */
class ClaimTransitionAction
{
    public static function startVerification(): Action
    {
        return self::make('startVerification', 'Start verification', WinnerClaimStatus::VerificationPending, 'verify', 'info', Heroicon::OutlinedMagnifyingGlass,
            'Marks the claim as under review. No identity check is performed automatically.');
    }

    public static function verify(): Action
    {
        return self::make('verifyClaim', 'Verify', WinnerClaimStatus::Verified, 'verify', 'warning', Heroicon::OutlinedShieldCheck,
            'Confirms you have verified this winner\'s claim. The winner moves to "verified".');
    }

    public static function approve(): Action
    {
        return self::make('approveClaim', 'Approve', WinnerClaimStatus::Approved, 'approve', 'success', Heroicon::OutlinedCheckCircle,
            'Approves the claim. The winner moves to "claim approved", ready for fulfilment.');
    }

    public static function reject(): Action
    {
        return self::make('rejectClaim', 'Reject', WinnerClaimStatus::Rejected, 'reject', 'danger', Heroicon::OutlinedXCircle,
            'Rejects the claim. Rejection is final in the current system — there is no resubmission path.',
            withRejectionReason: true);
    }

    private static function make(
        string $name,
        string $label,
        WinnerClaimStatus $to,
        string $ability,
        string $color,
        string|BackedEnum $icon,
        string $description,
        bool $withRejectionReason = false,
    ): Action {
        $fields = [];

        if ($withRejectionReason) {
            // winner_claims.rejection_reason — returned to the customer by
            // the account API (WinnerClaimResource), so labelled as such.
            $fields[] = Textarea::make('rejection_reason')
                ->label('Reason shown to the winner')
                ->required()
                ->minLength(5)
                ->maxLength(1000);
        }

        $fields[] = Textarea::make('note')
            ->label('Internal review note')
            ->helperText('Recorded in the winner audit log with your admin ID. Not shown to the customer.')
            ->required()
            ->minLength(5)
            ->maxLength(500);

        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize($ability) // WinnerClaimPolicy::verify|approve|reject
            ->visible(fn (WinnerClaim $record): bool => app(WinnerClaimService::class)->canTransition($record->status, $to))
            ->requiresConfirmation()
            ->modalHeading(fn (WinnerClaim $record): string => "{$label} claim #{$record->id}")
            ->modalDescription($description)
            ->modalSubmitActionLabel($label)
            ->schema($fields)
            ->action(function (WinnerClaim $record, array $data) use ($to, $label): void {
                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();

                try {
                    app(WinnerClaimService::class)->transition(
                        $record,
                        $to,
                        trim($data['note']),
                        rejectionReason: isset($data['rejection_reason']) ? trim($data['rejection_reason']) : null,
                        actorId: $admin->getKey(),
                    );
                } catch (WinnerClaimException $e) {
                    $record->refresh();

                    Notification::make()
                        ->title("{$label} not applied")
                        ->body($e->getMessage().' The claim may have been updated by someone else — nothing was changed.')
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title('Claim '.strtolower((string) WinnerClaimStatusBadge::label($to)))
                    ->success()
                    ->send();
            });
    }
}
