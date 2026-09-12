<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventFactory;
use WebaroundLabs\OpenAIAds\HostContext;
use WebaroundLabs\OpenAIAds\InvalidArgument;

/**
 * Turns the loose array a WordPress caller passes into a validated Event.
 *
 * Almost nothing happens here. The translation itself lives in the core's
 * `EventFactory`, because the Laravel adapter needs exactly the same one and two
 * copies of a validation boundary is how they stop agreeing. What is left is the
 * part that is genuinely WordPress: reading the request through this plugin's
 * own `RequestContext`, and announcing a dropped identity field as an action so
 * a site can log it.
 */
final class EventBuilder
{
    private readonly EventFactory $factory;

    public function __construct(
        private readonly RequestContext $context,
        Clock $clock,
    ) {
        $this->factory = new EventFactory(
            $clock,
            static function (string $field, string $reason): void {
                // Neither argument contains the value; a listener must not log
                // one either.
                \do_action('openai_ads_identity_field_dropped', $field, $reason);
            },
        );
    }

    /**
     * @param array<string, mixed> $data    Event data: amount, currency.
     * @param array<string, mixed> $options event_id, custom_event_name, opt_out, user,
     *                                      action_source, source_url, timestamp_ms,
     *                                      contents, plan_id.
     * @param HostContext|null     $context Overrides what this request knows. The
     *                                      collection endpoint needs it: a POST to
     *                                      /wp-json did not happen on the page the
     *                                      conversion did, so the request's own URL,
     *                                      cookies and address describe the wrong
     *                                      thing. Everything else leaves it null.
     *
     * @throws InvalidArgument when the caller supplied something the API cannot accept
     */
    public function build(
        string $eventName,
        array $data = [],
        array $options = [],
        ?HostContext $context = null,
    ): Event {
        return $this->factory->build(
            $eventName,
            $data,
            $options,
            $context ?? $this->context->forMeasurement(),
        );
    }
}
