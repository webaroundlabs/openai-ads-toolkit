<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * The shape of an event's `data` object.
 *
 * The shape decides which fields the payload may carry, which is why it is a
 * type rather than a string: `customer_action` accepts neither a contents array
 * nor a plan id, and that restriction should be visible in the type system.
 */
enum DataShape: string
{
    case Contents = 'contents';
    case CustomerAction = 'customer_action';
    case PlanEnrollment = 'plan_enrollment';
    case Custom = 'custom';

    /**
     * Whether the shape carries a `contents` array.
     *
     * `customer_action` does not - a lead has no line items - and sending one
     * anyway would be rejected by the API for the whole batch.
     */
    public function acceptsContents(): bool
    {
        return $this !== self::CustomerAction;
    }

    /** Only the subscription shapes and `custom` carry a plan identifier. */
    public function acceptsPlanId(): bool
    {
        return $this === self::PlanEnrollment || $this === self::Custom;
    }
}
