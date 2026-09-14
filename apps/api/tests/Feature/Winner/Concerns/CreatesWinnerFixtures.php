<?php

namespace Tests\Feature\Winner\Concerns;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Prize;
use App\Models\Ticket;
use App\Models\User;

/**
 * Builds a fully `drawn` competition — competition status, a completed
 * `draws` row, and its immutable `draw_results` row — exactly the P9
 * handoff state P10 starts from. Deliberately constructs this state
 * directly rather than running the real DrawService for every test
 * (DrawServiceTest/DrawConcurrencyTest already cover DrawService itself
 * exhaustively); a handful of tests below additionally exercise the
 * real CompetitionLifecycleService::transition() path where the P9->P10
 * event handoff itself is what's under test.
 */
trait CreatesWinnerFixtures
{
    /**
     * @return array{competition: Competition, winningUser: User, winningTicket: Ticket, draw: Draw, drawResult: DrawResult}
     */
    private function createDrawnCompetition(int $prizeCount = 1): array
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();

        if ($prizeCount > 0) {
            Prize::factory()->count($prizeCount)->for($competition)->create();
        }

        $winningUser = User::factory()->create();
        $order = Order::factory()->withStatus(OrderStatus::Paid)->for($winningUser)->create();
        $item = OrderItem::factory()->for($order)->for($competition)->create(['quantity' => 3]);
        $entry = Entry::query()->create([
            'user_id' => $winningUser->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => 3,
        ]);

        $tickets = [];
        for ($i = 1; $i <= 3; $i++) {
            $tickets[] = Ticket::query()->create([
                'entry_id' => $entry->id,
                'competition_id' => $competition->id,
                'order_id' => $order->id,
                'user_id' => $winningUser->id,
                'ticket_number' => $i,
                'allocated_at' => now(),
            ]);
        }
        $winningTicket = $tickets[0];

        $draw = Draw::factory()->for($competition)->create([
            'status' => DrawStatus::Completed,
            'attempt' => 1,
            'completed_at' => now(),
            'eligible_ticket_count' => 3,
            'eligibility_version' => 'v1:ticket.order.status=paid',
            'snapshot_checksum' => 'test-checksum',
            'randomness_method' => 'test',
        ]);

        $drawResult = DrawResult::create([
            'draw_id' => $draw->id,
            'competition_id' => $competition->id,
            'ticket_id' => $winningTicket->id,
            'ticket_number' => $winningTicket->ticket_number,
            'eligible_ticket_count' => 3,
            'selected_at' => now(),
        ]);

        return [
            'competition' => $competition,
            'winningUser' => $winningUser,
            'winningTicket' => $winningTicket,
            'draw' => $draw,
            'drawResult' => $drawResult,
        ];
    }
}
