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

    /** Mirrors `normalizations.city_region.max_length` in packages/spec/user.json. */
    private const CITY_REGION_MAX_LENGTH = 128;

    /** Mirrors `normalizations.postal_code.max_length` in packages/spec/user.json. */
    private const POSTAL_CODE_MAX_LENGTH = 32;

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
     * identifiers, so a blank or unusable one is treated as absent, which keeps
     * a half-filled checkout address from throwing. The one exception is
     * `$country`, where a non-empty value that is not a two-letter code is a
     * caller mistake worth naming rather than a missing field.
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

        // Geographic fields are never hashed, but they ARE normalized: the API
        // documents a rule for each and drops a value that does not satisfy it,
        // without reporting anything. A blank one is simply absent - a
        // half-filled checkout address must not throw - but a malformed country
        // code is a caller mistake worth naming, because "Romania" would look
        // delivered while matching nobody.
        if ($country !== null && trim($country) !== '') {
            $values['country'] = self::normalizeCountry($country);
        }

        foreach (['city' => $city, 'region' => $region] as $field => $raw) {
            if ($raw === null) {
                continue;
            }

            $normalized = self::normalizeCityOrRegion($raw);

            if ($normalized !== '') {
                $values[$field] = $normalized;
            }
        }

        if ($postalCode !== null) {
            $normalized = self::normalizePostalCode($postalCode);

            if ($normalized !== '') {
                $values['postal_code'] = $normalized;
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
     * Remove the four documented separators, then a leading "+", then leading
     * zeroes - and nothing else.
     *
     * OpenAI documents "8-15 digits after removing a leading +, leading zeroes,
     * whitespace, parentheses, periods, and hyphens". Removing every non-digit
     * instead looks equivalent and is not: "+1 (555) 123-4567 ext. 89" would
     * become "1555123456789", thirteen digits that pass every length check and
     * hash to a number belonging to nobody. Anything left over that is not a
     * digit is therefore refused rather than stripped.
     *
     * Dialling plans are still not parsed: "+00 44 (0)20 7946 0958" becomes
     * "4402079460958", inner zero included.
     *
     * @throws InvalidArgument when the result is not 8-15 digits
     */
    private static function normalizePhone(string $value): string
    {
        $compact = (string) preg_replace('/[\s()\.\-]/u', '', trim($value));

        if (str_starts_with($compact, '+')) {
            $compact = substr($compact, 1);
        }

        $digits = ltrim($compact, '0');

        // The raw number is deliberately absent from both messages below.
        if (preg_match('/^[0-9]*$/', $digits) !== 1) {
            throw new InvalidArgument(
                'phone must contain only digits once whitespace, parentheses, periods, '
                . 'hyphens, a leading plus and leading zeroes are removed. An extension or '
                . 'a letter is not stripped, because stripping it would silently hash a '
                . 'different number.',
            );
        }

        $length = strlen($digits);

        if ($length < 8 || $length > 15) {
            throw new InvalidArgument(sprintf(
                'phone must contain between 8 and 15 digits after normalization; got %d.',
                $length,
            ));
        }

        return $digits;
    }

    /**
     * ISO 3166-1 alpha-2, emitted uppercase.
     *
     * Upstream asks for "raw two-letter country codes, such as US" and states no
     * case rule; uppercase is the toolkit's pinned choice, recorded in the spec.
     * A country name rather than a code is refused: the API would drop it
     * without an error, leaving the caller believing it was sent.
     *
     * @throws InvalidArgument
     */
    private static function normalizeCountry(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('/^[A-Za-z]{2}$/', $trimmed) !== 1) {
            throw new InvalidArgument(sprintf(
                'country must be a two-letter ISO 3166-1 alpha-2 code such as "US"; got "%s".',
                $trimmed,
            ));
        }

        return strtoupper($trimmed);
    }

    /**
     * Trim, lowercase, cap at 128 characters.
     *
     * This is what the API does to the value on receipt. Doing it here too means
     * the string sent is the string stored, and that the Pixel and the
     * Conversions API carry the same one. Lowercasing is Unicode-aware for the
     * same reason as the name rule.
     */
    private static function normalizeCityOrRegion(string $value): string
    {
        $lowered = mb_strtolower(trim($value), 'UTF-8');

        return mb_substr($lowered, 0, self::CITY_REGION_MAX_LENGTH, 'UTF-8');
    }

    /**
     * Reduce to letters, digits, spaces and hyphens, cap at 32 characters.
     *
     * Characters outside that set are removed rather than refused: a postal code
     * arriving with a period or a slash is a formatting artefact, not a caller
     * mistake. Case is deliberately not folded - upstream states a lowercase
     * rule for cities and regions and states none here.
     */
    private static function normalizePostalCode(string $value): string
    {
        $filtered = (string) preg_replace('/[^A-Za-z0-9 \-]/u', '', trim($value));

        return trim(substr($filtered, 0, self::POSTAL_CODE_MAX_LENGTH));
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
