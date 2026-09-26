<?php

namespace App\Filament\Resources\Draws\Actions;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Filament\Resources\Draws\DrawResource;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Draw;
use App\Services\Draw\DrawException;
use App\Services\Draw\DrawService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

/**
 * "Run draw" — an additional, authorized trigger into the SAME P9 flow
 * the scheduler, DrawCompetitionJob and `php artisan competition:draw`
 * use: one synchronous call to DrawService::run($competition), exactly
 * as that artisan command makes it.
 *
 * The admin's only input is WHICH competition to draw. There is
 * deliberately no ticket, winner, seed, offset or randomness input:
 * DrawService owns locking, the draw_pending -> draw_in_progress
 * transition, stale-lease crash recovery, the eligibility snapshot and
 * checksum, CSPRNG selection (AutomatedDrawStrategy), the DrawResult,
 * the -> drawn transition that hands off to P10 winner creation, and the
 * draw audit log. Nothing here re-implements any of it; any extra field
 * a crafted request adds is simply never read.
 *
 * Offered competitions: `draw_pending`, and `draw_in_progress` (which
 * DrawService only acts on when the running attempt's lease has
 * expired — its crash-recovery path; otherwise it no-ops). That
 * availability list is UI convenience, not the authority: run() re-reads
 * the locked row and decides, so a draw started meanwhile by the
 * scheduler or another admin is never duplicated (it returns null).
 */
class RunDrawAction
{
    public static function make(): Action
    {
        return Action::make('runDraw')
            ->label('Run draw')
            ->icon(Heroicon::OutlinedPlay)
            ->color('danger')
            // Explicit server-side authorization (custom actions have no
            // automatic policy check) — DrawPolicy::run, i.e. draw.run.
            // Re-checked by Filament on mount and on call.
            ->authorize(fn (): bool => (bool) Auth::guard('admin')->user()?->can('run', Draw::class))
            ->disabled(fn (): bool => self::awaitingCompetitions() === [])
            ->tooltip(fn (): ?string => self::awaitingCompetitions() === [] ? 'No competition is currently awaiting a draw.' : null)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading('Run draw?')
            ->modalDescription('This executes the automated draw process for the selected competition. The winning ticket is selected by the draw engine from the eligible paid tickets — it cannot be chosen, influenced or undone here.')
            ->modalSubmitActionLabel('Run draw now')
            ->schema([
                Select::make('competition_id')
                    ->label('Competition')
                    ->required()
                    ->options(fn (): array => self::awaitingCompetitions())
                    ->native(false)
                    ->helperText('Only competitions awaiting a draw are listed.'),
            ])
            ->action(function (array $data, Component $livewire): void {
                $competition = Competition::query()->find($data['competition_id'] ?? null);

                if ($competition === null) {
                    self::notify('Nothing was run', 'That competition no longer exists.', 'warning');

                    return;
                }

                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();

                // P9 records no actor on draws/audit rows; this operational
                // log line is the only attribution of admin-triggered runs.
                Log::info('admin.draw.requested', [
                    'admin_id' => $admin->getKey(),
                    'competition_id' => $competition->id,
                    'competition_status' => $competition->status->value,
                ]);

                try {
                    $draw = app(DrawService::class)->run($competition);
                } catch (DrawException $e) {
                    // Deterministic domain rejection (e.g. no eligible
                    // tickets) — DrawService has already marked the
                    // attempt failed and audited it. Its message is
                    // domain-authored and safe to show.
                    self::notify('Draw failed', $e->getMessage().' The attempt was recorded as failed.', 'danger');

                    return;
                } catch (Throwable $e) {
                    Log::error('admin.draw.unexpected_failure', [
                        'admin_id' => $admin->getKey(),
                        'competition_id' => $competition->id,
                        'exception' => $e::class,
                    ]);
                    report($e);

                    self::notify('Draw failed unexpectedly', 'No winner was recorded. If an attempt had started it is marked failed; details are in the application logs.', 'danger');

                    return;
                }

                if ($draw === null) {
                    self::notify(
                        'Nothing was run',
                        'This competition is no longer awaiting a draw, or another draw attempt is still running (a running attempt is only recovered after its lease expires). No changes were made.',
                        'warning',
                    );

                    return;
                }

                if ($draw->status !== DrawStatus::Completed) {
                    // Lost a finalization race — another attempt produced
                    // the result. Report exactly that.
                    self::notify('Draw not completed by this attempt', $draw->failure_reason ?? 'Another attempt finalized this competition.', 'warning');
                    $livewire->redirect(DrawResource::getUrl('view', ['record' => $draw]));

                    return;
                }

                $ticketNumber = $draw->result()->value('ticket_number');

                self::notify('Draw completed', "Winning ticket no. {$ticketNumber}, selected by the draw engine.", 'success');
                $livewire->redirect(DrawResource::getUrl('view', ['record' => $draw]));
            });
    }

    /**
     * Competitions DrawService::run() could act on — see class docblock.
     * Bounded: only these two transient statuses, capped at 100.
     *
     * @return array<int, string>
     */
    public static function awaitingCompetitions(): array
    {
        return Competition::query()
            ->whereIn('status', [CompetitionStatus::DrawPending, CompetitionStatus::DrawInProgress])
            ->orderBy('draw_at')
            ->limit(100)
            ->get(['id', 'title', 'status'])
            ->mapWithKeys(fn (Competition $competition): array => [
                $competition->id => $competition->title.' — '.($competition->status === CompetitionStatus::DrawPending
                    ? 'awaiting draw'
                    : 'draw in progress (recoverable only after its lease expires)'),
            ])
            ->all();
    }

    private static function notify(string $title, string $body, string $status): void
    {
        Notification::make()->title($title)->body($body)->status($status)->persistent()->send();
    }
}
