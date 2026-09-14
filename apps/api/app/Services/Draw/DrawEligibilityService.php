<?php

namespace App\Services\Draw;

use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Defines and executes the ONE eligibility predicate for the draw
 * engine — see docs/DRAW_ENGINE_P9.md "Eligibility rules". Per
 * docs/TICKET_ENGINE_DESIGN_P7.md's own P8/P9 boundary note: "P9 reads
 * tickets/entries joined to Order.status = paid to determine the
 * eligible pool." Because `tickets.order_id` is already denormalized
 * from the parent Entry (P7), the eligibility join goes straight from
 * `tickets` to `orders` — no join through `entries` is needed at all
 * (entries carry no status of their own to check; they are never
 * edited or deleted after allocation).
 *
 * Eligible ticket, exactly: a `tickets` row whose `order_id` points to
 * an `orders` row with `status = 'paid'`. This excludes tickets whose
 * order is pending/payment_pending (impossible in practice — tickets
 * are only ever created for an already-paid order, see
 * TicketAllocationService), cancelled, failed, or refunded (the one
 * real, reachable exclusion: PaymentService::markRefunded() can move an
 * order from paid to refunded strictly after its tickets were already
 * allocated).
 */
class DrawEligibilityService
{
    /**
     * Bump this if the eligibility predicate itself ever changes —
     * every draw's audit trail records which version was used, so a
     * later definition change never silently reinterprets an old,
     * already-completed draw.
     */
    public const ELIGIBILITY_VERSION = 'v1:ticket.order.status=paid';

    /**
     * Builds the eligibility snapshot for a competition: how many
     * tickets are eligible right now, the overall (eligible or not)
     * maximum ticket_number (the search space DrawRandomnessService
     * samples from), and an integrity checksum over cheap aggregates
     * (count/min/max/sum) rather than a copy of every row — see
     * docs/DRAW_ENGINE_P9.md "Snapshot strategy".
     *
     * Must be called from inside the same DB transaction the caller
     * will also use for selection (see AutomatedDrawStrategy): under
     * MySQL's default REPEATABLE READ isolation, a transaction's first
     * read establishes a consistent snapshot that subsequent plain
     * (non-locking) reads in the SAME transaction continue to see, even
     * if another connection commits new rows afterward. That is the
     * opposite need from P7/P8's lockForUpdate() lesson (which existed
     * to escape a stale snapshot and see the latest committed data) —
     * here we deliberately WANT one fixed, internally consistent view
     * for the whole count-then-select operation, and specifically do
     * NOT lock the tickets/orders tables to get it (that would be far
     * heavier than necessary for a read-only snapshot).
     */
    public function snapshot(Competition $competition): DrawSnapshot
    {
        $eligible = DB::table('tickets')
            ->join('orders', 'tickets.order_id', '=', 'orders.id')
            ->where('tickets.competition_id', $competition->id)
            ->where('orders.status', OrderStatus::Paid->value)
            ->selectRaw('COUNT(*) as cnt, MIN(tickets.ticket_number) as min_num, MAX(tickets.ticket_number) as max_num, COALESCE(SUM(tickets.ticket_number), 0) as sum_num')
            ->first();

        $overallMax = (int) (Ticket::query()
            ->where('competition_id', $competition->id)
            ->max('ticket_number') ?? 0);

        $count = (int) $eligible->cnt;

        $checksum = hash('sha256', implode(':', [
            $competition->id,
            $count,
            $eligible->min_num ?? 'null',
            $eligible->max_num ?? 'null',
            (int) $eligible->sum_num,
        ]));

        return new DrawSnapshot(
            eligibleCount: $count,
            overallMaxTicketNumber: $overallMax,
            snapshotAt: now(),
            eligibilityVersion: self::ELIGIBILITY_VERSION,
            checksum: $checksum,
        );
    }

    /**
     * Point lookup: is this exact ticket_number (within this
     * competition) currently eligible? Used by the rejection-sampling
     * randomness strategy — a single indexed lookup joining on the
     * unique(competition_id, ticket_number) index, never a table scan.
     */
    public function ticketIfEligible(int $competitionId, int $ticketNumber): ?Ticket
    {
        /** @var Ticket|null */
        return Ticket::query()
            ->select('tickets.*')
            ->join('orders', 'tickets.order_id', '=', 'orders.id')
            ->where('tickets.competition_id', $competitionId)
            ->where('tickets.ticket_number', $ticketNumber)
            ->where('orders.status', OrderStatus::Paid->value)
            ->first();
    }

    /**
     * Fallback selection path: the k-th eligible ticket (0-indexed,
     * ordered by ticket_number). Returns exactly one row to PHP no
     * matter how large the competition is — see
     * docs/DRAW_ENGINE_P9.md "Selection algorithm" for why this exists
     * only as a bounded fallback, not the default path.
     */
    public function nthEligibleTicket(int $competitionId, int $offset): ?Ticket
    {
        /** @var Ticket|null */
        return Ticket::query()
            ->select('tickets.*')
            ->join('orders', 'tickets.order_id', '=', 'orders.id')
            ->where('tickets.competition_id', $competitionId)
            ->where('orders.status', OrderStatus::Paid->value)
            ->orderBy('tickets.ticket_number')
            ->offset($offset)
            ->limit(1)
            ->first();
    }
}
