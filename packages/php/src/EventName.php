<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * The events OpenAI Ads documents.
 *
 * Mirrors `packages/spec/events.json`. SpecParityTest asserts that this enum and
 * the spec agree on the catalogue, the data shapes and the support matrix, so
 * adding an event upstream turns this file red rather than letting it drift.
 */
enum EventName: string
{
    case PageViewed = 'page_viewed';
    case ContentsViewed = 'contents_viewed';
    case ItemsAdded = 'items_added';
    case CheckoutStarted = 'checkout_started';
    case OrderCreated = 'order_created';
    case LeadCreated = 'lead_created';
    case RegistrationCompleted = 'registration_completed';
    case AppointmentScheduled = 'appointment_scheduled';
    case SubscriptionCreated = 'subscription_created';
    case TrialStarted = 'trial_started';
    case Custom = 'custom';
    case AppInstalled = 'app_installed';
    case AppOpened = 'app_opened';

    /**
     * The shape the event's `data` object takes.
     */
    public function dataShape(): DataShape
    {
        return match ($this) {
            self::PageViewed,
            self::ContentsViewed,
            self::ItemsAdded,
            self::CheckoutStarted,
            self::OrderCreated => DataShape::Contents,

            self::LeadCreated,
            self::RegistrationCompleted,
            self::AppointmentScheduled,
            self::AppInstalled,
            self::AppOpened => DataShape::CustomerAction,

            self::SubscriptionCreated,
            self::TrialStarted => DataShape::PlanEnrollment,

            self::Custom => DataShape::Custom,
        };
    }

    /**
     * Action sources this event permits, or null when every one is allowed.
     *
     * @return list<ActionSource>|null
     */
    public function allowedActionSources(): ?array
    {
        return match ($this) {
            self::AppInstalled, self::AppOpened => [ActionSource::MobileApp],
            default => null,
        };
    }

    /**
     * Whether the browser Measurement Pixel can emit this event.
     *
     * The two app events are Conversions API only.
     */
    public function supportsPixel(): bool
    {
        return match ($this) {
            self::AppInstalled, self::AppOpened => false,
            default => true,
        };
    }

    /**
     * Whether the Conversions API accepts this event.
     *
     * Every documented event currently does. The method exists so the parity
     * test compares against the spec rather than against an assumption: if
     * OpenAI ever ships a Pixel-only event, that test fails here.
     */
    public function supportsCapi(): bool
    {
        return true;
    }

    public function requiresCustomEventName(): bool
    {
        return $this === self::Custom;
    }

    public function permits(ActionSource $actionSource): bool
    {
        $allowed = $this->allowedActionSources();

        return $allowed === null || in_array($actionSource, $allowed, true);
    }
}
