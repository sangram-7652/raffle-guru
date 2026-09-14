<?php

namespace App\Models;

use App\Enums\CompetitionStatus;
use Database\Factories\CompetitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'slug',
    'title',
    'short_description',
    'description',
    'category',
    'status',
    'entry_price',
    'currency',
    'max_entries',
    'max_entries_per_user',
    'images',
    'skill_question',
    'starts_at',
    'ends_at',
    'draw_at',
    'is_featured',
])]
class Competition extends Model
{
    /** @use HasFactory<CompetitionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'description' => 'array',
            'images' => 'array',
            'skill_question' => 'array',
            'status' => CompetitionStatus::class,
            'is_featured' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'draw_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Prize, $this>
     */
    public function prizes(): HasMany
    {
        return $this->hasMany(Prize::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Audit trail of lifecycle transitions (P8) — see
     * docs/COMPETITION_LIFECYCLE_P8.md.
     *
     * @return HasMany<CompetitionLifecycleEvent, $this>
     */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(CompetitionLifecycleEvent::class);
    }

    /**
     * Every draw attempt ever made for this competition (P9) — see
     * docs/DRAW_ENGINE_P9.md. Usually zero or one; more than one only
     * when an earlier attempt failed/crashed and was recovered.
     *
     * @return HasMany<Draw, $this>
     */
    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class);
    }

    /**
     * The final, immutable winner record (P9) — at most one per
     * competition, ever (database-enforced). Null until drawn.
     *
     * @return HasOne<DrawResult, $this>
     */
    public function drawResult(): HasOne
    {
        return $this->hasOne(DrawResult::class);
    }

    /**
     * The winner record (P10) — see docs/WINNER_CLAIM_P10.md. Null until
     * CreateWinnerJob resolves it from the draw result. Database-unique
     * on competitions.id, same guarantee as drawResult() above.
     *
     * @return HasOne<Winner, $this>
     */
    public function winner(): HasOne
    {
        return $this->hasOne(Winner::class);
    }

    /**
     * Competitions safe to show a public/customer-facing API — excludes
     * `draft` (unpublished) and `scheduled` (approved but not yet live).
     * Everything from `active` onward (including `sold_out`, `closed`,
     * `draw_pending`/`draw_in_progress`/`drawn`/`completed`) stays visible
     * — those are all still meaningful for a customer to see. See
     * docs/COMPETITIONS_API.md for the full reasoning.
     *
     * Deliberately NOT filtering by starts_at/ends_at here: P4 doesn't
     * automatically move a competition between statuses based on dates
     * (that's P8's lifecycle engine) — visibility today is driven purely
     * by the stored `status`, not computed from timestamps.
     *
     * @param  Builder<Competition>  $query
     * @return Builder<Competition>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            CompetitionStatus::Draft,
            CompetitionStatus::Scheduled,
        ]);
    }

    /**
     * Competitions that currently accept a new entry — narrower than
     * scopePublic(): a sold-out/closed/drawn competition is still worth
     * showing a customer (P4), but none of those should take a new cart
     * item or order line (P5). See docs/SCHEMA_DESIGN_P5.md.
     *
     * @param  Builder<Competition>  $query
     * @return Builder<Competition>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('status', CompetitionStatus::Active);
    }
}
