<?php

namespace App\Models;

use Database\Factories\DrawResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable, final outcome of a completed draw (P9) — see
 * docs/DRAW_ENGINE_P9.md "Draw result". Exactly one row may ever exist
 * per competition (database-level unique constraint on competition_id,
 * not just an application check — see the migration). Never updated
 * after creation: no code path in this application ever calls
 * ->update() on a DrawResult. If a correction is ever needed, it must
 * be an explicit administrative/audit action (out of scope for P9), not
 * a silent mutation.
 */
#[Fillable([
    'draw_id',
    'competition_id',
    'ticket_id',
    'ticket_number',
    'eligible_ticket_count',
    'selected_at',
])]
class DrawResult extends Model
{
    /** @use HasFactory<DrawResultFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
