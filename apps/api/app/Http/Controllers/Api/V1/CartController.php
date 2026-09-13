<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Competition;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $cart = $this->currentCart($request);

        // Explicit 200: a JsonResource wrapping a model normally infers
        // 201 when $resource->wasRecentlyCreated — true here on a user's
        // very first cart access (currentCart() lazily creates the row),
        // which is the wrong semantics for a GET/"show" endpoint.
        return (new CartResource($cart->load('items.competition')))
            ->response()
            ->setStatusCode(200);
    }

    public function store(AddCartItemRequest $request): CartResource
    {
        $cart = $this->currentCart($request);

        // Purchasable, not merely public (P4) — sold-out/closed/drawn
        // competitions are still visible but shouldn't take a new entry.
        // See docs/SCHEMA_DESIGN_P5.md.
        $competition = Competition::query()
            ->purchasable()
            ->find($request->validated('competition_id'));

        if (! $competition) {
            throw ValidationException::withMessages([
                'competition_id' => ['This competition is not available for entry.'],
            ]);
        }

        $existing = $cart->items()->where('competition_id', $competition->id)->first();
        $newQuantity = min(
            CartItem::MAX_QUANTITY,
            ($existing?->quantity ?? 0) + $request->validated('quantity'),
        );

        $this->assertWithinPerUserLimit($competition, $newQuantity);

        // Price is always resolved from the competition, never the
        // request — see docs/SCHEMA_DESIGN_P5.md "Price integrity".
        if ($existing) {
            $existing->update([
                'quantity' => $newQuantity,
                'unit_price' => $competition->entry_price,
                'currency' => $competition->currency,
            ]);
        } else {
            $cart->items()->create([
                'competition_id' => $competition->id,
                'quantity' => $newQuantity,
                'unit_price' => $competition->entry_price,
                'currency' => $competition->currency,
            ]);
        }

        return new CartResource($cart->fresh('items.competition'));
    }

    public function update(UpdateCartItemRequest $request, CartItem $item): CartResource
    {
        $this->authorizeOwnership($request, $item);

        if ($item->competition) {
            $this->assertWithinPerUserLimit($item->competition, $request->validated('quantity'));
        }

        $item->update(['quantity' => $request->validated('quantity')]);

        return new CartResource($item->cart->fresh('items.competition'));
    }

    public function destroy(Request $request, CartItem $item): CartResource
    {
        $this->authorizeOwnership($request, $item);

        $cart = $item->cart;
        $item->delete();

        return new CartResource($cart->fresh('items.competition'));
    }

    private function currentCart(Request $request): Cart
    {
        $user = $request->user();

        try {
            return $user->cart()->firstOrCreate([], ['currency' => 'GBP']);
        } catch (QueryException) {
            // Unique constraint on carts.user_id — extremely rare race
            // between two concurrent first-ever requests for this user.
            return $user->cart()->firstOrFail();
        }
    }

    /**
     * competitions.max_entries_per_user (P2) — a simple bounds check
     * against a stored column, not ticket inventory/reservation (P7).
     */
    private function assertWithinPerUserLimit(Competition $competition, int $quantity): void
    {
        if ($competition->max_entries_per_user !== null && $quantity > $competition->max_entries_per_user) {
            throw ValidationException::withMessages([
                'quantity' => ["This competition allows a maximum of {$competition->max_entries_per_user} entries per person."],
            ]);
        }
    }

    /**
     * A cart item has no user_id of its own — ownership is via its cart.
     * 404 (not 403) so a guessed ID never confirms another user's item
     * exists.
     */
    private function authorizeOwnership(Request $request, CartItem $item): void
    {
        if ($item->cart->user_id !== $request->user()->id) {
            abort(404);
        }
    }
}
