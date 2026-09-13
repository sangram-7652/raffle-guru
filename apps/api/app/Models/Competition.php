<?php

namespace App\Models;

use App\Enums\CompetitionStatus;
use Database\Factories\CompetitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
