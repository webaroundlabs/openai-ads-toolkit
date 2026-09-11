<?php

/**
 * A Laravel controller that measures a lead once - correctly.
 *
 * Two things are worth copying from this, and both are easy to get wrong:
 *
 * 1. The event is recorded AFTER the lead is persisted, not before. A
 *    validation failure or a database error must not produce a conversion.
 * 2. The event id is the lead's own primary key, and it is handed back to the
 *    browser so the Pixel event matches rather than duplicates.
 *
 * `track()` fills in the five things only the request knows - the source URL
 * under the configured privacy policy, the `__oppref` and `__obref` cookies, the
 * client IP and the user agent. Doing that by hand is how three of the five
 * end up missing. `OpenAIAds::event()` returns the built Event instead of
 * queueing it, for code that wants to inspect or amend it first, and
 * `Event::create()` is the fully typed form underneath both.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use WebaroundLabs\OpenAIAds\Laravel\Facades\OpenAIAds;

final class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        // The conversion is the lead existing. Everything below happens only
        // once it does.
        $lead = Lead::create($validated);

        // Queued, so the visitor is not waiting on an ad platform. Never throws:
        // a measurement failure cannot fail the request that created the lead.
        OpenAIAds::track('lead_created', [], [
            // The lead's own id, on both sides. Never a second, minted one.
            'event_id' => (string) $lead->id,
            // Raw values in; normalized and hashed before anything leaves.
            'user' => [
                'email' => $lead->email,
                'phone' => $lead->phone,
            ],
        ]);

        // The browser needs this id to report the same conversion once.
        return response()->json(['eventId' => (string) $lead->id], 201);
    }
}
