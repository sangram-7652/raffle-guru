<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Competition;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()
            ->orders()
            ->with(['items', 'latestPayment'])
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $reference): OrderResource
    {
        // Scoped to the authenticated user, not a bare id/reference lookup
        // — a guessed reference for someone else's order 404s, it never
        // confirms the order exists.
        $order = $request->user()
            ->orders()
            ->with(['items', 'latestPayment'])
            ->where('reference', $reference)
            ->firstOrFail();

        return new OrderResource($order);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $user->cart()->with('items.competition')->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Your basket is empty.'],
            ]);
        }

        // Re-check and re-price every line fresh from the database right
        // now — never trust the cart item's snapshot, which may be stale,
        // and never trust anything the client sent. A competition that
        // went sold-out/closed since it was added rejects the whole
        // order rather than silently dropping the line.
        $lines = [];
        foreach ($cart->items as $item) {
            $competition = Competition::query()->purchasable()->find($item->competition_id);

            if (! $competition) {
                $name = $item->competition->title ?? 'One of the items';

                throw ValidationException::withMessages([
                    'cart' => ["\"{$name}\" is no longer available for entry. Please remove it from your basket."],
                ]);
            }

            $lines[] = [
                'competition' => $competition,
                'prize_id' => $competition->prizes()->value('id'),
                'quantity' => $item->quantity,
                'unit_price' => $competition->entry_price,
            ];
        }

        $subtotal = array_sum(array_map(
            fn (array $line) => $line['unit_price'] * $line['quantity'],
            $lines,
        ));

        $order = $this->createOrderWithRetry($user, $cart, $lines, $subtotal);

        return (new OrderResource($order->load(['items', 'latestPayment'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @param  array<int, array{competition: Competition, prize_id: int|null, quantity: int, unit_price: int}>  $lines
     */
    private function createOrderWithRetry(User $user, Cart $cart, array $lines, int $subtotal, int $attempt = 0): Order
    {
        try {
            return DB::transaction(function () use ($user, $cart, $lines, $subtotal) {
                $order = Order::create([
                    'user_id' => $user->id,
                    'reference' => $this->generateReference(),
                    // Never anything but Pending here — P5 does not mark
                    // an order paid or allocate tickets. See
                    // docs/SCHEMA_DESIGN_P5.md.
                    'status' => OrderStatus::Pending,
                    'currency' => 'GBP',
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                ]);

                foreach ($lines as $line) {
                    $order->items()->create([
                        'competition_id' => $line['competition']->id,
                        'prize_id' => $line['prize_id'],
                        'competition_title' => $line['competition']->title,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'currency' => $line['competition']->currency,
                        'line_total' => $line['unit_price'] * $line['quantity'],
                    ]);
                }

                $cart->items()->delete();

                return $order;
            });
        } catch (QueryException $e) {
            // Duplicate-key on orders.reference — astronomically rare
            // (900,000 possible codes) but the database constraint, not
            // this check, is what actually guarantees uniqueness. Retry
            // the whole attempt with a fresh reference a few times before
            // giving up.
            if ($attempt < 3 && str_contains($e->getMessage(), 'orders_reference_unique')) {
                return $this->createOrderWithRetry($user, $cart, $lines, $subtotal, $attempt + 1);
            }

            throw $e;
        }
    }

    private function generateReference(): string
    {
        return 'RG-'.random_int(100000, 999999);
    }
}
