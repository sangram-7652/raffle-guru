<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cart_id', 'competition_id', 'quantity', 'unit_price', 'currency'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    /**
     * Deliberately generous per-line cap — not a real inventory/business
     * rule (that's competitions.max_entries_per_user, enforced separately
     * in CartController), just a block on an obviously-abusive single
     * request. See docs/SCHEMA_DESIGN_P5.md.
     */
    public const MAX_QUANTITY = 100;

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function lineTotal(): int
    {
        return $this->unit_price * $this->quantity;
    }
}
