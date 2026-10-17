<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionClaimResource;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Services\Promotions\PromotionClaimService;
use App\Services\Promotions\PromotionNotAvailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Requires auth:sanctum + verified (see routes/api.php) — a verified,
 * authenticated account is mandatory to claim (see
 * docs/EMAIL_CAPTURE.md "Is a verified, authenticated account
 * mandatory to claim?"). The authenticated user is always the only
 * identity involved; nothing about who is claiming is ever read from
 * the request body.
 */
class PromotionClaimController extends Controller
{
    public function __construct(
        private readonly PromotionClaimService $claims,
    ) {}

    public function show(Request $request, string $slug): PromotionClaimResource|JsonResponse
    {
        $promotion = $this->findPromotionOrFail($slug);

        $claim = PromotionClaim::query()
            ->where('promotion_id', $promotion->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($claim === null) {
            return response()->json(['data' => null]);
        }

        return new PromotionClaimResource($claim);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $promotion = $this->findPromotionOrFail($slug);

        try {
            $claim = $this->claims->claim($request->user(), $promotion);
        } catch (PromotionNotAvailableException $e) {
            throw ValidationException::withMessages([
                'promotion' => [$e->getMessage()],
            ]);
        }

        // Explicit 200, not the 201 a JsonResource would infer from
        // $claim->wasRecentlyCreated — claiming is idempotent (see
        // PromotionClaimService), so a brand-new claim and a repeat
        // confirmation of an already-fulfilled one must look identical
        // to the caller, same reasoning as CartController::show().
        return (new PromotionClaimResource($claim))
            ->response()
            ->setStatusCode(200);
    }

    private function findPromotionOrFail(string $slug): Promotion
    {
        return Promotion::query()->where('slug', $slug)->firstOrFail();
    }
}
