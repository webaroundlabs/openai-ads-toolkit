<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * Where the conversion happened.
 *
 * Required on every Conversions API event. The Measurement Pixel supplies it
 * itself and must not be given one.
 */
enum ActionSource: string
{
    case Web = 'web';
    case MobileApp = 'mobile_app';
    case Offline = 'offline';
    case PhysicalStore = 'physical_store';
    case PhoneCall = 'phone_call';
    case Email = 'email';
    case Other = 'other';
}
