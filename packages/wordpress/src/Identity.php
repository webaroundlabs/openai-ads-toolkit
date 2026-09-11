<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\UserData;

/**
 * Translates the plugin's loose identity array into the core's types.
 *
 * Two callers need this - the event builder, for the Conversions API, and the
 * Pixel, for a server-rendered `init({ user })` - and they must agree, because
 * the whole point is that a browser event and its server twin describe the same
 * person. One definition, therefore, rather than two that drift.
 *
 * The keys are the documented snake_case field names, so a developer reading
 * OpenAI's documentation recognizes them. Normalization, hashing and the
 * decision about what is usable all stay in the core; nothing here touches a
 * value.
 */
final class Identity
{
    /**
     * Documented field name => the core's parameter name.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'email' => 'email',
        'phone' => 'phone',
        'external_id' => 'externalId',
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'country' => 'country',
        'city' => 'city',
        'region' => 'region',
        'postal_code' => 'postalCode',
    ];

    /**
     * Identity the core can use, with anything unusable dropped.
     *
     * Values reaching this plugin were typed into a form by a visitor, so a
     * malformed phone number is untrusted input rather than a programming
     * error - and losing a paid order to one is the wrong trade. The core's
     * untrusted-input constructor drops the offending field and keeps the rest.
     *
     * The hook receives the field name and the core's reason. Neither contains
     * the value, and a listener must not log one either.
     *
     * @param array<string, mixed>  $raw
     * @param RequestContext|null   $context adds the three fields only the request
     *                                       knows - the obref cookie, the client IP
     *                                       and the user agent. Omit it for the Pixel,
     *                                       where the browser supplies its own.
     */
    public static function fromArray(array $raw, ?RequestContext $context = null): UserData
    {
        $values = [];

        foreach (self::FIELDS as $key => $parameter) {
            $values[$parameter] = isset($raw[$key]) && is_string($raw[$key]) ? $raw[$key] : null;
        }

        if ($context !== null) {
            $values['obref'] = $context->obref();
            $values['ipAddress'] = $context->ipAddress();
            $values['userAgent'] = $context->userAgent();
        }

        return UserData::fromUntrusted(
            $values,
            static function (string $field, string $reason): void {
                \do_action('openai_ads_identity_field_dropped', $field, $reason);
            },
        );
    }

    /**
     * Raw identity, hashed into the shape `oaiq("init", { user })` wants.
     *
     * Singular keys and scalar values, unlike the Conversions API's plural
     * arrays. Never throws: a page must render even when a value is unusable.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, string>
     */
    public static function forPixel(array $raw): array
    {
        return self::fromArray($raw)->toPixelArray();
    }
}
