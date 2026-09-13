<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntryResource;
use App\Models\Entry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only — no mutation endpoints exist, or should ever be added
 * (customers cannot edit/delete entries or tickets). Every action here
 * is scoped to the authenticated user; a cross-user id returns 404, not
 * 403 — same convention as cart/order ownership checks (P5/P6).
 */
class EntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = $request->user()
            ->entries()
            ->with(['competition', 'order', 'tickets'])
            ->latest()
            ->paginate(10);

        return EntryResource::collection($entries);
    }

    public function show(Request $request, Entry $entry): EntryResource
    {
        if ($entry->user_id !== $request->user()->id) {
            abort(404);
        }

        return new EntryResource($entry->load(['competition', 'order', 'tickets']));
    }
}
