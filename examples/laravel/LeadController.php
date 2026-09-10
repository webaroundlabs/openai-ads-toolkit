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
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\Laravel\Facades\OpenAIAds;
use WebaroundLabs\OpenAIAds\UserData;

final class LeadController extends Controller
{
    public function store(Request $request, Clock $clock): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        // The conversion is the lead existing. Everything below happens only
        // once it does.
        $lead = Lead::create($validated);

        $context = OpenAIAds::context();

        $event = Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId((string) $lead->id),
            timestampMs: $clock->nowMs(),
            actionSource: ActionSource::Web,
            sourceUrl: $context->sourceUrl(),
            user: UserData::create(
                email: $lead->email,
                obref: $context->obref(),
                ipAddress: $context->ipAddress(),
                userAgent: $context->userAgent(),
            ),
            oppref: $context->oppref(),
        );

        // Queued, so the visitor is not waiting on an ad platform. This never
        // throws: a measurement failure cannot fail the request that created
        // the lead.
        OpenAIAds::queue($event);

        // The browser needs this id to report the same conversion once.
        return response()->json(['eventId' => (string) $lead->id], 201);
    }
}
