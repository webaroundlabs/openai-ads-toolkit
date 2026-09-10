<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

/**
 * Typed access to the plugin's stored options.
 *
 * Everything is namespaced under one option so the plugin owns a single row, and
 * that row is not autoloaded - it is needed on a handful of requests, not on
 * every page load.
 */
final class Settings
{
    public const OPTION = 'openai_ads_settings';

    /**
     * Lets the API key live in wp-config.php instead of the database.
     *
     * Preferred: it keeps the key out of database dumps and staging copies, and
     * out of reach of anyone who can edit options but not files.
     */
    public const KEY_CONSTANT = 'OPENAI_ADS_CAPI_KEY';

    public const PIXEL_ID_CONSTANT = 'OPENAI_ADS_PIXEL_ID';

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = \get_option(self::OPTION, []);
            $this->cache = is_array($stored) ? $stored : [];
        }

        return $this->cache;
    }

    public function pixelId(): ?string
    {
        if (\defined(self::PIXEL_ID_CONSTANT)) {
            $value = (string) \constant(self::PIXEL_ID_CONSTANT);

            return trim($value) !== '' ? trim($value) : null;
        }

        return $this->stringOrNull('pixel_id');
    }

    /**
     * The Conversions API key. Server-side only.
     *
     * Never render this, never log it, never pass it to a script. The settings
     * screen shows a masked placeholder rather than the value.
     */
    public function capiKey(): ?string
    {
        if (\defined(self::KEY_CONSTANT)) {
            $value = (string) \constant(self::KEY_CONSTANT);

            return trim($value) !== '' ? trim($value) : null;
        }

        return $this->stringOrNull('capi_key');
    }

    /** Whether the key is fixed in wp-config.php and therefore not editable here. */
    public function capiKeyIsConstant(): bool
    {
        return \defined(self::KEY_CONSTANT);
    }

    public function pixelEnabled(): bool
    {
        return $this->bool('pixel_enabled', true) && $this->pixelId() !== null;
    }

    public function capiEnabled(): bool
    {
        return $this->bool('capi_enabled', true)
            && $this->pixelId() !== null
            && $this->capiKey() !== null;
    }

    /** Send events to the API's documented validation mode instead of recording them. */
    public function validateOnly(): bool
    {
        return $this->bool('validate_only', false);
    }

    public function debug(): bool
    {
        return $this->bool('debug', false);
    }

    public function integrationSource(): string
    {
        $value = $this->stringOrNull('integration_source');

        return $value ?? 'webaroundlabs-wordpress';
    }

    public function timeout(): int
    {
        $value = $this->all()['timeout'] ?? 5;

        return max(1, min(30, (int) $value));
    }

    /**
     * The origin this site legitimately measures from.
     *
     * Defaults to the site's own home URL. Used to reject a spoofed Host header
     * before it can put a third party's domain into the advertiser's data.
     */
    public function canonicalOrigin(): ?string
    {
        $configured = $this->stringOrNull('canonical_origin') ?? \home_url();

        $parts = \wp_parse_url($configured);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower((string) $parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    public function stripQueryString(): bool
    {
        return $this->bool('strip_query_string', true);
    }

    /**
     * Validate and normalize a submitted settings array.
     *
     * This is the boundary: everything here arrives from an HTTP form and is
     * untrusted. An existing key is preserved when the field is submitted blank,
     * so saving the page without retyping the secret does not wipe it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $current = $this->all();

        $pixelId = isset($input['pixel_id']) ? \sanitize_text_field((string) $input['pixel_id']) : '';
        $submittedKey = isset($input['capi_key']) ? trim((string) $input['capi_key']) : '';

        $key = $submittedKey !== ''
            ? \sanitize_text_field($submittedKey)
            : (string) ($current['capi_key'] ?? '');

        $integrationSource = isset($input['integration_source'])
            ? \sanitize_text_field((string) $input['integration_source'])
            : '';

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $integrationSource) !== 1) {
            $integrationSource = 'webaroundlabs-wordpress';
        }

        $canonical = isset($input['canonical_origin'])
            ? \esc_url_raw(trim((string) $input['canonical_origin']))
            : '';

        return [
            'pixel_id' => $pixelId,
            'capi_key' => $key,
            'pixel_enabled' => !empty($input['pixel_enabled']),
            'capi_enabled' => !empty($input['capi_enabled']),
            'validate_only' => !empty($input['validate_only']),
            'debug' => !empty($input['debug']),
            'strip_query_string' => !empty($input['strip_query_string']),
            'integration_source' => $integrationSource,
            'canonical_origin' => $canonical,
            'timeout' => max(1, min(30, (int) ($input['timeout'] ?? 5))),
        ];
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->all()[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function bool(string $key, bool $default): bool
    {
        $all = $this->all();

        if (!array_key_exists($key, $all)) {
            return $default;
        }

        return (bool) $all[$key];
    }
}
