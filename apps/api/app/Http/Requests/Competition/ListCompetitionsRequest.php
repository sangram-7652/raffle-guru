<?php

namespace App\Http\Requests\Competition;

use App\Enums\CompetitionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCompetitionsRequest extends FormRequest
{
    /**
     * Allow-list of sortable keys — never let a query parameter pick an
     * arbitrary database column. See CompetitionController for what each
     * one maps to.
     */
    public const SORTS = ['ending_soon', 'newest', 'price', 'featured'];

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
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(CompetitionStatus::class)],
            'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
