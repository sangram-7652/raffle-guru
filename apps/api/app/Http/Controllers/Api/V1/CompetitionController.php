<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Competition\ListCompetitionsRequest;
use App\Http\Resources\CompetitionResource;
use App\Models\Competition;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompetitionController extends Controller
{
    /**
     * Sort key => [column, default direction]. Only these keys are ever
     * reachable — see ListCompetitionsRequest::SORTS — a query parameter
     * can never pick an arbitrary column.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SORT_COLUMNS = [
        'ending_soon' => ['draw_at', 'asc'],
        'newest' => ['created_at', 'desc'],
        'price' => ['entry_price', 'asc'],
        'featured' => ['is_featured', 'desc'],
    ];

    public function index(ListCompetitionsRequest $request): AnonymousResourceCollection
    {
        $search = $request->validated('search');
        $category = $request->validated('category');
        $status = $request->validated('status');
        $featured = $request->boolean('featured');
        $hasFeaturedFilter = $request->has('featured');
        $sort = $request->validated('sort') ?? 'ending_soon';
        [$column, $defaultDirection] = self::SORT_COLUMNS[$sort];
        $direction = $request->validated('direction') ?? $defaultDirection;
        $perPage = $request->validated('per_page') ?? 12;

        $competitions = Competition::query()
            ->public()
            ->with('prizes')
            ->when($search, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%"),
            ))
            ->when($category, fn ($query, $category) => $query->where('category', $category))
            ->when($status, fn ($query, $status) => $query->where('status', $status))
            ->when($hasFeaturedFilter, fn ($query) => $query->where('is_featured', $featured))
            ->orderBy($column, $direction)
            // Stable secondary order so equal-value rows (e.g. every
            // non-featured competition when sorting by "featured") don't
            // shuffle between pages.
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return CompetitionResource::collection($competitions);
    }

    public function show(string $slug): CompetitionResource
    {
        $competition = Competition::query()
            ->public()
            ->with('prizes')
            ->where('slug', $slug)
            ->firstOrFail();

        return new CompetitionResource($competition);
    }
}
