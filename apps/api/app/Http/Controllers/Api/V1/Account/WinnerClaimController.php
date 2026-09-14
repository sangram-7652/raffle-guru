<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Winner\SubmitWinnerClaimRequest;
use App\Http\Resources\WinnerClaimResource;
use App\Models\Winner;
use App\Services\Winner\WinnerClaimException;
use App\Services\Winner\WinnerClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * See docs/WINNER_CLAIM_P10.md "Claim rules". Only the actual winner can
 * ever reach these endpoints — ownership is enforced here, before any
 * service call, exactly like WinnerController. Every ownership
 * relationship (winner -> user, claim -> winner) is derived server-side
 * from the authenticated session and the route-bound Winner; nothing
 * about identity is ever read from the request body.
 */
class WinnerClaimController extends Controller
{
    public function __construct(
        private readonly WinnerClaimService $claims,
    ) {}

    public function show(Request $request, Winner $winner): WinnerClaimResource|JsonResponse
    {
        $this->authorizeOwner($request, $winner);

        $claim = $winner->claim;

        if ($claim === null) {
            return response()->json(['data' => null]);
        }

        return new WinnerClaimResource($claim);
    }

    /**
     * POST /v1/account/winners/{winner}/claim — the one customer
     * mutation in this whole domain. Accepts only `notes` (see
     * SubmitWinnerClaimRequest) — winner_id/user_id/competition_id/
     * ticket_id/draw_id/prize_id/status are never read from the
     * request; the winner is entirely the ownership-checked route
     * model.
     */
    public function store(SubmitWinnerClaimRequest $request, Winner $winner): WinnerClaimResource
    {
        $this->authorizeOwner($request, $winner);

        try {
            $claim = $this->claims->submit($winner, $request->validated('notes'));
        } catch (WinnerClaimException $e) {
            throw ValidationException::withMessages([
                'claim' => [$e->getMessage()],
            ]);
        }

        return new WinnerClaimResource($claim);
    }

    private function authorizeOwner(Request $request, Winner $winner): void
    {
        if ($winner->user_id !== $request->user()->id) {
            abort(404);
        }
    }
}
