<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\WordPress\Http\Ingest;

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

    /**
     * Integrations a site has to switch on deliberately.
     *
     * @var list<string>
     */
    private const OFF_BY_DEFAULT = ['user_registration'];

    /** Lets the collection endpoint's secret live in wp-config.php. */
    public const INGEST_SECRET_CONSTANT = 'OPENAI_ADS_INGEST_SECRET';

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

    /**
     * Whether a named integration should run.
     *
     * Defaults to on: a site that installs this plugin alongside Contact Form 7
     * wants its leads measured, and having to hunt for a second switch is a
     * worse default than having to turn one off.
     */
    /**
     * Integrations default to ON, with one deliberate exception.
     *
     * Installing a form plugin's integration and having it do nothing until
     * somebody finds a checkbox is the wrong default: the site owner installed
     * this plugin to measure conversions. The exception is
     * `user_registration`, whose host is WordPress itself, so it is always
     * "available" and would otherwise switch itself on for every site. It
     * reports every account creation, and on a WooCommerce site or one with an
     * importer that is not what anybody meant by a conversion.
     */
    public function integrationEnabled(string $id): bool
    {
        $all = $this->all();
        $integrations = isset($all['integrations']) && is_array($all['integrations'])
            ? $all['integrations']
            : [];

        if (!array_key_exists($id, $integrations)) {
            return !in_array($id, self::OFF_BY_DEFAULT, true);
        }

        return (bool) $integrations[$id];
    }

    /**
     * Whether to hand batches to Action Scheduler when the site has it.
     *
     * On by default: it costs nothing where the queue is absent, and where it is
     * present it stops a fatal error mid-request from losing the conversion.
     */
    public function useScheduler(): bool
    {
        return $this->bool('use_scheduler', true);
    }

    public function stripQueryString(): bool
    {
        return $this->bool('strip_query_string', true);
    }

    /**
     * How consent is decided: automatically, by the site, or not at all.
     *
     * Defaults to automatic detection, which finds a consent plugin where one
     * exists and answers yes where none does. See Consent for why "yes" is the
     * uncomfortable but correct default, and why the settings screen warns about
     * it rather than silently measuring nothing.
     */
    public function consentMode(): string
    {
        return $this->stringOrNull('consent_mode') ?? Consent::MODE_AUTO;
    }

    /**
     * Whether the REST collection endpoint accepts events.
     *
     * Off by default, and the only setting in this class where that is a
     * security decision rather than a preference: an open endpoint that forwards
     * conversions lets anyone who finds it write into the advertiser's data.
     */
    public function ingestEnabled(): bool
    {
        return $this->bool('ingest_enabled', false) && $this->ingestSecret() !== null;
    }

    /**
     * The shared secret callers must present.
     *
     * Like the API key, it may live in wp-config.php instead of the database -
     * which keeps it out of database dumps and staging copies, and out of reach
     * of anyone who can edit options but not files.
     */
    public function ingestSecret(): ?string
    {
        if (\defined(self::INGEST_SECRET_CONSTANT)) {
            $value = trim((string) \constant(self::INGEST_SECRET_CONSTANT));

            return $value !== '' ? $value : null;
        }

        return $this->stringOrNull('ingest_secret');
    }

    public function ingestSecretIsConstant(): bool
    {
        return \defined(self::INGEST_SECRET_CONSTANT);
    }

    /** The URL callers post to. Public information; the secret is not. */
    public function ingestUrl(): string
    {
        return \rest_url(Ingest::NAMESPACE . Ingest::ROUTE);
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
            'use_scheduler' => !empty($input['use_scheduler']),
            'integration_source' => $integrationSource,
            'canonical_origin' => $canonical,
            'timeout' => max(1, min(30, (int) ($input['timeout'] ?? 5))),
            'integrations' => $this->sanitizeIntegrations($input),
            'consent_mode' => $this->consentModeFrom($input),
            'ingest_enabled' => !empty($input['ingest_enabled']),
            'ingest_secret' => $this->ingestSecretFrom($input, $current),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, bool>
     */
    private function sanitizeIntegrations(array $input): array
    {
        /*
         * The hidden "present" list is the authority, not the checkboxes.
         *
         * An unchecked box is absent from the POST body entirely, so deriving
         * the set of integrations from what was submitted would silently
         * re-enable every one the site owner had just switched off. The form
         * therefore states which integrations it rendered, and each of those is
         * on only if its box came back.
         */
        $known = isset($input['integrations_present']) && is_array($input['integrations_present'])
            ? $input['integrations_present']
            : null;

        if ($known === null) {
            // The section was not rendered at all; leave what is stored.
            $current = $this->all()['integrations'] ?? [];

            return is_array($current) ? array_map('boolval', $current) : [];
        }

        $submitted = isset($input['integrations']) && is_array($input['integrations'])
            ? $input['integrations']
            : [];

        $result = [];

        foreach ($known as $id) {
            $id = (string) $id;

            if (preg_match('/^[a-z0-9_]{1,64}$/', $id) !== 1) {
                continue;
            }

            $result[$id] = !empty($submitted[$id]);
        }

        return $result;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    /**
     * Keep, rotate or mint the endpoint's secret.
     *
     * Minted rather than typed: a secret somebody chooses is a secret somebody
     * can guess. 32 bytes from the platform CSPRNG, hex encoded.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     */
    private function ingestSecretFrom(array $input, array $current): string
    {
        $existing = isset($current['ingest_secret']) && is_string($current['ingest_secret'])
            ? $current['ingest_secret']
            : '';

        if (!empty($input['ingest_rotate']) || ($existing === '' && !empty($input['ingest_enabled']))) {
            return bin2hex(random_bytes(32));
        }

        return $existing;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function consentModeFrom(array $input): string
    {
        $mode = isset($input['consent_mode']) ? (string) $input['consent_mode'] : Consent::MODE_AUTO;

        // Either one of the three modes, or the id of a provider the site has.
        // Anything else came from a tampered form and falls back to automatic.
        return $mode === '' ? Consent::MODE_AUTO : \sanitize_key($mode);
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
