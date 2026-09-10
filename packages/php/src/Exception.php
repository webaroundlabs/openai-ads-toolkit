<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * Marker for every exception this library throws.
 *
 * Lets an integrator catch everything from this package without catching
 * unrelated failures:
 *
 *     try { ... } catch (\WebaroundLabs\OpenAIAds\Exception $e) { ... }
 */
interface Exception extends \Throwable
{
}
