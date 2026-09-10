<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * Input rejected at the library's public boundary.
 *
 * Messages name the offending field and, where one is known, the event id.
 * They never contain a raw identifier: an email address, a phone number or an
 * external id must not reach a log or an error reporter through an exception
 * message.
 */
final class InvalidArgument extends \InvalidArgumentException implements Exception
{
}
