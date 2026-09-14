<?php

namespace App\Http\Resources;

use App\Enums\CompetitionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list — see UserResource (P3) for the same convention.
 *
 * skill_question deliberately omits `answer_index`: exposing it would
 * defeat the point of a skill-testing question (see
 * docs/SCHEMA_DESIGN_P2.md). No other internal/admin field (timestamps
 * aside from the public ones, soft-delete state, etc.) is included.
 *
 * `draw` (P9) is included ONLY once the competition is actually
 * `drawn`, and only the two facts a public raffle result conventionally
 * publishes — the winning ticket number and when. No draw id, no
 * strategy/RNG method, no snapshot checksum, no attempt history, no
 * winner identity: all of that is either internal draw-engine detail
 * that has no public API contract, or P10's ("winner publishing"). See
 * docs/DRAW_ENGINE_P9.md "Public API behavior".
 */
class CompetitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status->value,
            'entry_price' => $this->entry_price,
            'currency' => $this->currency,
            'max_entries' => $this->max_entries,
            'max_entries_per_user' => $this->max_entries_per_user,
            'images' => $this->images,
            'skill_question' => $this->skill_question ? [
                'prompt' => $this->skill_question['prompt'] ?? null,
                'options' => $this->skill_question['options'] ?? [],
            ] : null,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'draw_at' => $this->draw_at?->toIso8601String(),
            'is_featured' => $this->is_featured,
            'prizes' => PrizeResource::collection($this->whenLoaded('prizes')),
            'draw' => $this->when(
                $this->status === CompetitionStatus::Drawn && $this->drawResult !== null,
                fn () => [
                    'ticket_number' => $this->drawResult->ticket_number,
                    'drawn_at' => $this->drawResult->selected_at?->toIso8601String(),
                ],
            ),
        ];
    }
}
