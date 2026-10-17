<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public read model — deliberately coarse. `is_open` is the one signal
 * the footer CTA needs; exact capacity/claim counts are not exposed
 * here (see docs/EMAIL_CAPTURE.md "privacy posture").
 */
class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'is_open' => $this->isOpenAt(now()) && $this->fulfilledClaimsCount() < $this->total_capacity,
        ];
    }
}
