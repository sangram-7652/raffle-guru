<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\WinnerResource;
use App\Models\Winner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only, customer-facing — see docs/WINNER_CLAIM_P10.md "API".
 * Every action is scoped to the authenticated user; a cross-user id
 * returns 404, not 403 — same ownership convention as
 * App\Http\Controllers\Api\V1\EntryController (P7/P9). No mutation
 * endpoint exists here or should: winner-status/verification/delivery
 * changes are exclusively server/operator-driven (see
 * App\Services\Winner\WinnerLifecycleService).
 */
class WinnerController extends Controller
{
    private const EAGER = ['competition.prizes', 'drawResult', 'claim', 'delivery.prize'];

    /**
     * GET /api/v1/account/winner — the caller's single most recent
     * winner record, or `{ "data": null }` if they have never won.
     * A convenience singular alongside index()/show() for the common
     * "do I currently have a winner to act on" check.
     */
    public function current(Request $request): JsonResource|JsonResponse
    {
        $winner = $request->user()
            ->winners()
            ->with(self::EAGER)
            ->latest('id')
            ->first();

        if ($winner === null) {
            return response()->json(['data' => null]);
        }

        return new WinnerResource($winner);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $winners = $request->user()
            ->winners()
            ->with(self::EAGER)
            ->latest('id')
            ->paginate(10);

        return WinnerResource::collection($winners);
    }

    public function show(Request $request, Winner $winner): WinnerResource
    {
        if ($winner->user_id !== $request->user()->id) {
            abort(404);
        }

        return new WinnerResource($winner->load(self::EAGER));
    }
}
