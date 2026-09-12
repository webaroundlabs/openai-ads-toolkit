<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Whether this visitor has agreed to be measured.
 *
 * The plugin ships no cookie banner and makes no privacy decision of its own.
 * What it does is ASK, and the whole design question is who it asks.
 *
 * Leaving that entirely to a filter - the previous arrangement - is correct for
 * a library and wrong for a plugin somebody installs from the directory. It
 * means a site owner has to write PHP, most will not, and the default is to
 * measure. That is the wrong way round to be wrong.
 *
 * So it looks for a consent plugin first. The primary target is the **WP Consent
 * API**, a shared standard that Complianz, CookieYes, Borlabs, Real Cookie
 * Banner and others all publish into - one integration rather than one per
 * vendor, and one that keeps working when a vendor renames its own functions.
 * Complianz's own function is read directly as well, because it is the most
 * common banner on WordPress and does not need its bridge switched on.
 *
 * Every other banner is listed but **not read**. It is detected by class, named
 * on the settings screen, and accompanied by an instruction. Inventing a
 * function name for a plugin nobody here can test against would produce a
 * consent check that answers confidently and wrongly, which is worse than
 * admitting ignorance.
 *
 * The `openai_ads_consent` filter still runs last and still wins. A site with
 * its own rule keeps it.
 */
final class Consent
{
    /** Find a consent plugin and use it. The default. */
    public const MODE_AUTO = 'auto';

    /** The site answers through the `openai_ads_consent` filter itself. */
    public const MODE_FILTER = 'filter';

    /** No gating here. The site handles it somewhere else, or does not need to. */
    public const MODE_OFF = 'off';

    /**
     * The consent category this plugin needs.
     *
     * Measurement for advertising is marketing, not statistics. Asking for the
     * statistics category instead would collect under a permission the visitor
     * did not give for this.
     */
    private const CATEGORY = 'marketing';

    /**
     * The consent plugins this one knows about.
     *
     * `ask` is a function taking a category name and returning a boolean. Where
     * it is null the banner can be seen but not read, and `present` names the
     * class that proves it is installed.
     *
     * Order matters for automatic detection: the shared standard comes first,
     * because a vendor's own function is the thing more likely to move under it.
     *
     * @var array<string, array{label: string, ask: string|null, present: string|null}>
     */
    private const BANNERS = [
        'wp_consent_api' => ['label' => 'WP Consent API', 'ask' => 'wp_has_consent', 'present' => null],
        'complianz' => ['label' => 'Complianz', 'ask' => 'cmplz_has_consent', 'present' => null],
        'cookieyes' => ['label' => 'CookieYes', 'ask' => null, 'present' => 'Cookie_Law_Info'],
        'cookiebot' => ['label' => 'Cookiebot', 'ask' => null, 'present' => 'Cookiebot_WP'],
        'borlabs' => ['label' => 'Borlabs Cookie', 'ask' => null, 'present' => 'BorlabsCookie'],
        'real_cookie_banner' => ['label' => 'Real Cookie Banner', 'ask' => null, 'present' => 'RealCookieBanner\\Core'],
        'iubenda' => ['label' => 'iubenda', 'ask' => null, 'present' => 'iubenda_cookie_solution'],
    ];

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * The answer, for this request.
     *
     * The filter runs last and unconditionally, so a site can override anything
     * decided here - including a detected banner it disagrees with.
     */
    public function granted(): bool
    {
        $granted = match ($this->settings->consentMode()) {
            self::MODE_OFF, self::MODE_FILTER => true,
            default => $this->fromProvider(),
        };

        /**
         * The last word on whether this visitor may be measured.
         *
         * Returning false means the event is never constructed - not built and
         * then discarded. A refused consent is a normal, silent outcome, not an
         * error.
         *
         *     add_filter( 'openai_ads_consent', fn () => my_own_rule() );
         *
         * @param bool $granted What the plugin worked out on its own.
         */
        return (bool) \apply_filters('openai_ads_consent', $granted);
    }

    /**
     * Which banner is answering, or null when none is.
     *
     * `auto` takes the first readable one; any other mode names one explicitly
     * and falls back to none if that plugin has since been deactivated.
     */
    public function activeProvider(): ?string
    {
        $mode = $this->settings->consentMode();

        if ($mode === self::MODE_OFF || $mode === self::MODE_FILTER) {
            return null;
        }

        if ($mode !== self::MODE_AUTO) {
            return $this->ask($mode) === null ? null : $mode;
        }

        foreach (array_keys($this->banners()) as $id) {
            if ($this->ask($id) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Banners present AND readable, as id => label.
     *
     * @return array<string, string>
     */
    public function availableProviders(): array
    {
        $available = [];

        foreach ($this->banners() as $id => $banner) {
            if ($this->ask($id) !== null) {
                $available[$id] = $banner['label'];
            }
        }

        return $available;
    }

    /**
     * Banners that are installed but that this plugin cannot read.
     *
     * The settings screen names them and says what to do, which is the whole
     * reason for knowing about them at all.
     *
     * @return array<string, string> id => label
     */
    public function unreadableBanners(): array
    {
        $found = [];

        foreach ($this->banners() as $id => $banner) {
            $present = $banner['present'];

            if ($this->ask($id) === null && is_string($present) && class_exists($present)) {
                $found[$id] = $banner['label'];
            }
        }

        return $found;
    }

    /**
     * Whether this site is measuring everybody without asking anything.
     *
     * Not an error - plenty of sites have no European visitors, or gate consent
     * upstream at a CDN. But it is a state the site owner should learn by
     * looking at the screen, rather than from a complaint.
     */
    public function isUngated(): bool
    {
        $mode = $this->settings->consentMode();

        // Either choice is deliberate, so neither is a surprise worth warning about.
        if ($mode === self::MODE_OFF || $mode === self::MODE_FILTER) {
            return false;
        }

        return $this->activeProvider() === null && !\has_filter('openai_ads_consent');
    }

    /** @return array<string, string> */
    public static function modes(): array
    {
        return [
            self::MODE_AUTO => 'Detect a consent plugin automatically',
            self::MODE_FILTER => 'I answer through the openai_ads_consent filter',
            self::MODE_OFF => 'Do not gate on consent here',
        ];
    }

    public function label(string $id): string
    {
        return $this->banners()[$id]['label'] ?? $id;
    }

    /**
     * The banner list, filterable.
     *
     * Not an extension point for its own sake: a site running a banner that is
     * not listed can add it, given a function that takes a category name and
     * returns a boolean.
     *
     *     add_filter( 'openai_ads_consent_providers', function ( array $b ) {
     *         $b['my_banner'] = [
     *             'label'   => 'My Banner',
     *             'ask'     => 'my_has_consent',
     *             'present' => null,
     *         ];
     *         return $b;
     *     } );
     *
     * @return array<string, array{label: string, ask: string|null, present: string|null}>
     */
    private function banners(): array
    {
        /** @var mixed $filtered */
        $filtered = \apply_filters('openai_ads_consent_providers', self::BANNERS);

        return is_array($filtered) ? $filtered : self::BANNERS;
    }

    /**
     * The callable that answers for this banner, or null if there is not one.
     *
     * `is_callable` rather than `function_exists` because the list is
     * filterable, so the name arrived from outside this class and may be
     * anything at all. A Closure rather than the name, so what comes back is
     * something callable rather than a string somebody hopes is one.
     */
    private function ask(string $id): ?\Closure
    {
        $banner = $this->banners()[$id] ?? null;

        if (!is_array($banner)) {
            return null;
        }

        $ask = $banner['ask'] ?? null;

        return is_string($ask) && is_callable($ask) ? \Closure::fromCallable($ask) : null;
    }

    /**
     * Ask whichever banner is active.
     *
     * No banner means no answer, and no answer means yes. That default is
     * deliberate and it is the uncomfortable one: defaulting to "no" would make
     * the plugin silently measure nothing on any site that has not configured a
     * banner, and the owner would hunt for the fault for days. The settings
     * screen carries the warning instead - see isUngated().
     */
    private function fromProvider(): bool
    {
        $provider = $this->activeProvider();
        $ask = $provider === null ? null : $this->ask($provider);

        return $ask === null || (bool) $ask(self::CATEGORY);
    }
}
