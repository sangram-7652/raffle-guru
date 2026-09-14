<?php

namespace App\Services\Tickets;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place an Order's paid line items become Entry/Ticket
 * records. See docs/TICKET_ENGINE_DESIGN_P7.md for the full design —
 * this class implements exactly that document: one transaction per
 * order, an order-row lock for idempotency/concurrency, a
 * competition-row lock (competitions visited in ascending id order, to
 * avoid deadlocking against another order's allocation) guarding the
 * capacity/per-user checks and the ticket-number assignment together.
 *
 * P8 addition: once a batch of tickets brings a competition to
 * capacity, this is also where `active -> sold_out` is triggered — see
 * docs/COMPETITION_LIFECYCLE_P8.md "Where each transition actually
 * happens". It reuses the competition row lock already held here; no
 * new lock, no extra round trip.
 */
class TicketAllocationService
{
    public function __construct(
        private readonly CompetitionLifecycleService $lifecycle,
    ) {}

    /**
     * Idempotent: safe to call 0, 1, or many times for the same order.
     * Allocates every order item that doesn't already have an Entry;
     * if any item can't be allocated (capacity/per-user limit), the
     * whole call rolls back — no partial allocation.
     *
     * @throws CapacityExceededException|PerUserLimitExceededException
     */
    public function allocateOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // Reload fresh, under lock — never trust whatever state the
            // caller's $order instance happened to have in memory. This
            // lock is the per-order idempotency/concurrency boundary: a
            // second concurrent call for the same order blocks here
            // until the first finishes.
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status !== OrderStatus::Paid) {
                // Only a paid order may allocate — see
                // docs/TICKET_ENGINE_DESIGN_P7.md "Order eligibility".
                // Not an error: a job could plausibly run before/without
                // ever seeing a paid order in some retry scenario.
                return;
            }

            $items = OrderItem::query()
                ->where('order_id', $lockedOrder->id)
                ->orderBy('competition_id')
                ->get();

            foreach ($items as $item) {
                // Per-item idempotency: an earlier, separate successful
                // call already allocated this item — leave it alone.
                if (Entry::query()->where('order_item_id', $item->id)->exists()) {
                    continue;
                }

                $this->allocateItem($lockedOrder, $item);
            }
        });
    }

    private function allocateItem(Order $order, OrderItem $item): void
    {
        /** @var Competition $competition */
        $competition = Competition::query()->lockForUpdate()->findOrFail($item->competition_id);

        // lockForUpdate() here is not optional: MySQL's default
        // REPEATABLE READ isolation means a plain SELECT COUNT(*) keeps
        // reading the transaction's original consistent snapshot even
        // while we hold the competition row lock — the row lock alone
        // only serializes writers on the *competition* row, it does not
        // by itself make an ordinary read of a *different* table
        // (tickets) see another transaction's later commit. A locking
        // read always reads the latest committed data regardless of
        // isolation level, which is what actually makes this count
        // correct under concurrency. (Confirmed empirically — without
        // this, the concurrency test below still kept the ticket count
        // within capacity thanks to the `unique(competition_id,
        // ticket_number)` safety net, but via a raw
        // UniqueConstraintViolationException instead of the intended
        // CapacityExceededException — i.e. correct outcome, wrong/
        // uncaught exception type reaching the job.)
        $allocatedForCompetition = Ticket::query()
            ->where('competition_id', $competition->id)
            ->lockForUpdate()
            ->count();

        // Competition.max_entries is a required (NOT NULL) column — see
        // docs/SCHEMA_DESIGN_P2.md / the competitions migration — every
        // competition has a real, finite cap; only max_entries_per_user
        // can be unset.
        if ($allocatedForCompetition + $item->quantity > $competition->max_entries) {
            throw new CapacityExceededException(
                "Competition {$competition->id} has no capacity left for order item {$item->id}.",
            );
        }

        $allocatedForUser = Ticket::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $order->user_id)
            ->lockForUpdate()
            ->count();

        if ($competition->max_entries_per_user !== null
            && $allocatedForUser + $item->quantity > $competition->max_entries_per_user) {
            throw new PerUserLimitExceededException(
                "User {$order->user_id} would exceed the per-user limit on competition {$competition->id}.",
            );
        }

        $entry = Entry::create([
            'user_id' => $order->user_id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => $item->quantity,
        ]);

        $now = now();
        $rows = [];
        for ($i = 1; $i <= $item->quantity; $i++) {
            $rows[] = [
                'entry_id' => $entry->id,
                'competition_id' => $competition->id,
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'ticket_number' => $allocatedForCompetition + $i,
                'allocated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk insert — never load/iterate existing tickets into PHP
        // memory to allocate a new batch (see docs/TICKET_ENGINE_DESIGN_P7.md
        // "Performance").
        Ticket::insert($rows);

        Log::info('ticket.allocation.item_allocated', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'competition_id' => $competition->id,
            'quantity' => $item->quantity,
        ]);

        // Capacity is now authoritative for this decision (P8) — the
        // count used here is the exact same locked count already
        // computed above, not a second counter. A no-op if the
        // competition wasn't `active` (e.g. already sold_out from a
        // concurrent item in the same order) or if it isn't actually at
        // capacity yet.
        if ($allocatedForCompetition + $item->quantity >= $competition->max_entries) {
            $this->lifecycle->transition($competition, CompetitionStatus::SoldOut, 'capacity reached');
        }
    }
}
