<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * Identity data for conversion matching.
 *
 * Callers pass RAW values. Normalization and hashing happen here, once, at the
 * boundary - so that every adapter in the toolkit produces the same digest for
 * the same person. Two adapters normalizing an email differently is a silent
 * matching failure, which is why the rules live in one place and are pinned by
 * `packages/spec/fixtures/normalization.cases.json`.
 *
 * Raw values are never stored beyond the constructor, never logged, and never
 * placed in an exception message.
 *
 * The Conversions API and the Measurement Pixel want the same digests in
 * different shapes - `emails_sha256: ["..."]` versus `email_sha256: "..."` -
 * hence a serializer per destination. Only the Conversions API one exists so
 * far; `toPixelArray()` arrives with the JavaScript package.
 */
final class UserData
{
    /**
     * Logical field => [Conversions API key, is the wire value a list].
     *
     * Mirrors `packages/spec/user.json`; SpecParityTest asserts they agree.
     * Order is the emission order, so payloads are byte-reproducible.
     *
     * @var array<string, array{string, bool}>
     */
    private const CAPI = [
        'email' => ['emails_sha256', true],
        'phone' => ['phone_numbers_sha256', true],
        'external_id' => ['external_ids_sha256', true],
        'first_name' => ['first_names_sha256', true],
        'last_name' => ['last_names_sha256', true],
        'country' => ['countries', true],
        'city' => ['cities', true],
        'region' => ['regions', true],
        'postal_code' => ['postal_codes', true],
        'obref' => ['obref', false],
        'ip_address' => ['ip_address', false],
        'user_agent' => ['user_agent', false],
        'android_advertising_id' => ['android_advertising_id', false],
    ];

    /**
     * @param array<string, string> $values logical field => wire-ready value
     */
    private function __construct(
        private readonly array $values,
    ) {
    }

    /**
     * All arguments are raw and optional.
     *
     * Identity fields that normalize to nothing are rejected rather than
     * dropped: hashing an empty string produces a valid-looking digest that
     * matches nobody, and silently sending it would degrade matching with no
     * signal. Geographic fields are lenient by contrast - they are hints, not
     * identifiers, so a blank one is simply treated as absent, which keeps a
     * half-filled checkout address from throwing.
     *
     * @param string|null $obref The opaque `__obref` cookie value. Never hashed,
     *                           never parsed. Not to be confused with the
     *                           event-level `oppref`, which belongs on Event.
     *
     * @throws InvalidArgument when a supplied identity value is unusable
     */
    public static function create(
        ?string $email = null,
        ?string $phone = null,
        ?string $externalId = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $country = null,
        ?string $city = null,
        ?string $region = null,
        ?string $postalCode = null,
        ?string $obref = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $androidAdvertisingId = null,
    ): self {
        $values = [];

        if ($email !== null) {
            $values['email'] = self::hash(self::normalizeEmail($email), 'email');
        }

        if ($phone !== null) {
            $values['phone'] = self::hash(self::normalizePhone($phone), 'phone');
        }

        if ($externalId !== null) {
            $values['external_id'] = self::hash(trim($externalId), 'external_id');
        }

        if ($firstName !== null) {
            $values['first_name'] = self::hash(self::normalizeName($firstName), 'first_name');
        }

        if ($lastName !== null) {
            $values['last_name'] = self::hash(self::normalizeName($lastName), 'last_name');
        }

        foreach (
            [
                'country' => $country,
                'city' => $city,
                'region' => $region,
                'postal_code' => $postalCode,
            ] as $field => $raw
        ) {
            if ($raw !== null && trim($raw) !== '') {
                $values[$field] = trim($raw);
            }
        }

        if ($obref !== null) {
            $trimmed = trim($obref);
            if ($trimmed === '') {
                throw new InvalidArgument('obref was provided but is empty; pass null instead.');
            }
            // Opaque: stored exactly as given, never normalized.
            $values['obref'] = $obref;
        }

        if ($ipAddress !== null) {
            if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgument('ip_address must be a valid IPv4 or IPv6 address.');
            }
            $values['ip_address'] = $ipAddress;
        }

        if ($userAgent !== null) {
            if (trim($userAgent) === '') {
                throw new InvalidArgument('user_agent was provided but is empty; pass null instead.');
            }
            $values['user_agent'] = $userAgent;
        }

        if ($androidAdvertisingId !== null) {
            $pattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
            if (preg_match($pattern, $androidAdvertisingId) !== 1) {
                throw new InvalidArgument('android_advertising_id must be a UUID.');
            }
            $values['android_advertising_id'] = $androidAdvertisingId;
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * The Conversions API shape: plural keys, list values for matchable fields.
     *
     * Every hashable and geographic field is emitted as a list even with a
     * single value, because the API reads the first three unique values per
     * list field. Multi-value input is not yet accepted by create(); when it is,
     * it arrives as a separate named constructor rather than by widening these
     * parameters, which would be a signature change.
     *
     * @return array<string, list<string>|string>
     */
    public function toCapiArray(): array
    {
        $payload = [];

        foreach (self::CAPI as $field => [$key, $isList]) {
            if (!isset($this->values[$field])) {
                continue;
            }

            $payload[$key] = $isList ? [$this->values[$field]] : $this->values[$field];
        }

        return $payload;
    }

    private static function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * Remove every non-digit, then leading zeroes.
     *
     * OpenAI documents removing the leading "+", leading zeroes, whitespace and
     * punctuation but does not fix an order, and the order changes the result.
     * The order pinned here is deterministic and is recorded in
     * `packages/spec/user.json`. It does not attempt to parse dialling plans:
     * "+00 44 (0)20 7946 0958" becomes "4402079460958", inner zero included.
     *
     * @throws InvalidArgument when the result is not 8-15 digits
     */
    private static function normalizePhone(string $value): string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', trim($value)), '0');
        $length = strlen($digits);

        if ($length < 8 || $length > 15) {
            // The raw number is deliberately absent from this message.
            throw new InvalidArgument(sprintf(
                'phone must contain between 8 and 15 digits after normalization; got %d.',
                $length,
            ));
        }

        return $digits;
    }

    /**
     * Lowercase, then remove whitespace and ASCII punctuation, preserving
     * non-ASCII characters.
     *
     * mb_strtolower is required. PHP's byte-wise strtolower() leaves a name
     * such as "Stefanescu" spelled with diacritics unchanged, while
     * JavaScript's toLowerCase() lowercases it - two different digests for the
     * same person, and no error anywhere. Pinned in the spec fixtures.
     */
    private static function normalizeName(string $value): string
    {
        $lowered = mb_strtolower(trim($value), 'UTF-8');
        $withoutSpace = (string) preg_replace('/\s+/u', '', $lowered);

        return (string) preg_replace('/[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/u', '', $withoutSpace);
    }

    /**
     * @throws InvalidArgument when the value normalized away to nothing
     */
    private static function hash(string $normalized, string $field): string
    {
        if ($normalized === '') {
            throw new InvalidArgument(sprintf(
                '%s was provided but normalized to an empty value; pass null instead.',
                $field,
            ));
        }

        return hash('sha256', $normalized);
    }
}
