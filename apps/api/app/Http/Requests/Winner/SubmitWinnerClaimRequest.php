<?php

namespace App\Http\Requests\Winner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately the ONLY field this request accepts. No winner_id,
 * user_id, competition_id, ticket_id, draw_id, prize_id, or status is
 * ever read from the request — see
 * docs/WINNER_CLAIM_P10.md "Claim API input". The winner itself comes
 * from the route-bound model, already ownership-checked by the
 * controller before this request's validated() data is used.
 */
class SubmitWinnerClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
