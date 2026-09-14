<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\DrawResult;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Draw\DrawEligibilityService;
use App\Services\Draw\DrawService;
use App\Services\Draw\NoEligibleTicketsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;

class DrawServiceTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    private function service(): DrawService
    {
        return app(DrawService::class);
    }

    // -- Lifecycle guards (test matrix items 1-5) --------------------------------------------------

    public function test_draw_pending_can_start_and_complete_a_draw(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 5);

        $draw = $this->service()->run($competition);

        $this->assertNotNull($draw);
        $this->assertSame(DrawStatus::Completed, $draw->status);
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    public function test_active_cannot_start_a_draw(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $draw = $this->service()->run($competition);

        $this->assertNull($draw);
        $this->assertSame(0, Draw::query()->where('competition_id', $competition->id)->count());
    }

    public function test_closed_cannot_directly_become_drawn(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        $draw = $this->service()->run($competition);

        $this->assertNull($draw);
        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    public function test_draw_in_progress_with_a_live_attempt_cannot_start_another_draw(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawInProgress)->create();
        Draw::factory()->for($competition)->create([
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now(), // fresh — well within the lease
        ]);

        $draw = $this->service()->run($competition);

        $this->assertNull($draw);
        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->count());
    }

    public function test_drawn_cannot_be_redrawn(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 5);
        $first = $this->service()->run($competition);
        $this->assertNotNull($first);

        $second = $this->service()->run($competition->fresh());

        $this->assertNull($second);
        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->where('status', DrawStatus::Completed)->count());
    }

    // -- Eligibility (test matrix items 6-12) --------------------------------------------------

    public function test_paid_ticket_is_eligible(): void
    {
        ['competition' => $competition, 'eligibleTicketNumbers' => $eligible] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);

        $draw = $this->service()->run($competition);

        $this->assertContains($draw->result->ticket_number, $eligible);
    }

    public function test_pending_order_ticket_is_excluded(): void
    {
        $eligibility = app(DrawEligibilityService::class);
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();
        $ticket = $this->rawTicket($competition, \App\Enums\OrderStatus::Pending, 1);

        $this->assertNull($eligibility->ticketIfEligible($competition->id, 1));
        $this->assertSame(0, $eligibility->snapshot($competition)->eligibleCount);
        $this->assertNotNull($ticket); // sanity: the ticket row does exist
    }

    public function test_failed_order_ticket_is_excluded(): void
    {
        $eligibility = app(DrawEligibilityService::class);
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();
        $this->rawTicket($competition, \App\Enums\OrderStatus::Failed, 1);

        $this->assertNull($eligibility->ticketIfEligible($competition->id, 1));
    }

    public function test_cancelled_order_ticket_is_excluded(): void
    {
        $eligibility = app(DrawEligibilityService::class);
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();
        $this->rawTicket($competition, \App\Enums\OrderStatus::Cancelled, 1);

        $this->assertNull($eligibility->ticketIfEligible($competition->id, 1));
    }

    public function test_refunded_order_ticket_is_excluded(): void
    {
        ['competition' => $competition, 'eligibleTicketNumbers' => $eligible, 'ineligibleTicketNumbers' => $refunded] =
            $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3, refundedCount: 2);

        $eligibility = app(DrawEligibilityService::class);
        foreach ($refunded as $number) {
            $this->assertNull($eligibility->ticketIfEligible($competition->id, $number));
        }

        $draw = $this->service()->run($competition);
        $this->assertContains($draw->result->ticket_number, $eligible);
        $this->assertNotContains($draw->result->ticket_number, $refunded);
    }

    public function test_a_ticket_can_never_be_deleted_out_from_under_its_entry_or_order(): void
    {
        // "deleted entry excluded" (test matrix #11): entries/tickets
        // cannot actually be deleted at all — every relevant foreign key
        // is restrictOnDelete (P7) — so this is a structural guarantee,
        // not a runtime eligibility check. Eligibility itself never
        // consults the entries table (see DrawEligibilityService's
        // docblock): it joins tickets straight to orders, so even a
        // hypothetical missing entry would have no bearing on whether a
        // ticket is eligible.
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::Active, 1);
        $entry = \App\Models\Entry::query()->where('competition_id', $competition->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $entry->delete();
    }

    public function test_wrong_competition_ticket_is_excluded(): void
    {
        // Both competitions independently allocate a ticket numbered #1
        // (numbering is per-competition, P7) — competition A's
        // eligibility lookup for #1 must resolve to ITS OWN ticket, never
        // competition B's, even though the number coincides.
        ['competition' => $competitionA] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 1);
        ['competition' => $competitionB] = $this->createCompetitionWithTickets(CompetitionStatus::Active, 1);

        $eligibility = app(DrawEligibilityService::class);
        $resolved = $eligibility->ticketIfEligible($competitionA->id, 1);

        $this->assertNotNull($resolved);
        $this->assertSame($competitionA->id, $resolved->competition_id);
        $ticketBId = Ticket::query()->where('competition_id', $competitionB->id)->firstOrFail()->id;
        $this->assertNotSame($ticketBId, $resolved->id);
    }

    // -- Persistence (test matrix items 18-23) --------------------------------------------------

    public function test_a_draw_record_is_created(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);

        $draw = $this->service()->run($competition);

        $this->assertDatabaseHas('draws', ['id' => $draw->id, 'competition_id' => $competition->id]);
    }

    public function test_snapshot_metadata_is_persisted(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 4);

        $draw = $this->service()->run($competition)->fresh();

        $this->assertSame(4, $draw->eligible_ticket_count);
        $this->assertSame(DrawEligibilityService::ELIGIBILITY_VERSION, $draw->eligibility_version);
        $this->assertNotEmpty($draw->snapshot_checksum);
        $this->assertNotNull($draw->snapshot_at);
        $this->assertSame('csprng:random_int', $draw->randomness_method);
    }

    public function test_result_is_created_exactly_once(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);

        $this->service()->run($competition);
        $this->service()->run($competition->fresh());
        $this->service()->run($competition->fresh());

        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
    }

    public function test_audit_events_are_persisted(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);

        $draw = $this->service()->run($competition);

        $events = DrawAuditLog::query()->where('draw_id', $draw->id)->pluck('event')->all();
        $this->assertContains('draw.started', $events);
        $this->assertContains('draw.completed', $events);
    }

    public function test_competition_becomes_drawn_atomically_with_the_result(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);

        $draw = $this->service()->run($competition);

        // If the competition is drawn, the result MUST already exist —
        // there is no code path that sets one without the other (both
        // happen inside DrawService::finalize()'s single transaction).
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
        $this->assertNotNull(DrawResult::query()->where('competition_id', $competition->id)->first());
        $this->assertSame($draw->id, DrawResult::query()->where('competition_id', $competition->id)->first()->draw_id);
    }

    public function test_a_draw_result_is_never_updated_by_any_application_code_path(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        $draw = $this->service()->run($competition);
        $result = DrawResult::query()->where('competition_id', $competition->id)->first();
        $originalTicketNumber = $result->ticket_number;
        $originalSelectedAt = $result->selected_at->toIso8601String();

        // Further calls must never touch the existing result.
        $this->service()->run($competition->fresh());
        $this->service()->run($competition->fresh());

        $result->refresh();
        $this->assertSame($originalTicketNumber, $result->ticket_number);
        $this->assertSame($originalSelectedAt, $result->selected_at->toIso8601String());
    }

    // -- Failure: zero eligible tickets --------------------------------------------------

    public function test_a_competition_with_zero_eligible_tickets_fails_without_being_marked_drawn(): void
    {
        // Every ticket refunded after allocation — a real, reachable
        // scenario (see docs/DRAW_ENGINE_P9.md "Eligibility rules").
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 0, refundedCount: 3);

        $this->expectException(NoEligibleTicketsException::class);

        try {
            $this->service()->run($competition);
        } finally {
            $this->assertSame(CompetitionStatus::DrawInProgress, $competition->fresh()->status);
            $this->assertSame(0, DrawResult::query()->where('competition_id', $competition->id)->count());
            $draw = Draw::query()->where('competition_id', $competition->id)->first();
            $this->assertSame(DrawStatus::Failed, $draw->status);
            $this->assertNotNull($draw->failure_reason);
        }
    }

    /**
     * Directly inserts a ticket bound to an order of an arbitrary
     * status, bypassing TicketAllocationService — used only to exercise
     * eligibility exclusions that (per docs/DRAW_ENGINE_P9.md
     * "Eligibility rules") cannot occur via the real allocation flow,
     * but must still be excluded if they ever did.
     */
    private function rawTicket(Competition $competition, \App\Enums\OrderStatus $orderStatus, int $ticketNumber): Ticket
    {
        $user = \App\Models\User::factory()->create();
        $order = Order::factory()->withStatus($orderStatus)->for($user)->create();
        $item = \App\Models\OrderItem::factory()->for($order)->for($competition)->create(['quantity' => 1]);
        $entry = \App\Models\Entry::query()->create([
            'user_id' => $user->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => 1,
        ]);

        return Ticket::query()->create([
            'entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'ticket_number' => $ticketNumber,
            'allocated_at' => now(),
        ]);
    }
}
