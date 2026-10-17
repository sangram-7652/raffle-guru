<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated, read-only — lets a logged-out visitor see
 * whether the footer's advertised offer is currently running before
 * they're asked to log in/register to claim it. See
 * Account\PromotionClaimController for the authenticated claim itself.
 */
class PromotionController extends Controller
{
    public function show(string $slug): PromotionResource|JsonResponse
    {
        $promotion = Promotion::query()->where('slug', $slug)->first();

        if ($promotion === null) {
            // Not an error — a visitor hitting this before any admin
            // has configured a promotion (or for a stale/retired slug)
            // is routine, not exceptional. The frontend treats this the
            // same as "not open" and shows the plain subscribe form.
            return response()->json(['data' => null]);
        }

        return new PromotionResource($promotion);
    }
}
