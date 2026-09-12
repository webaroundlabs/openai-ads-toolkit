<?php

declare(strict_types=1);

/**
 * Stand-ins for the consent plugins this one can read.
 *
 * A real site either has these functions or does not, and `function_exists()`
 * is the whole detection. PHP cannot undefine a function, so the tests instead
 * filter the provider LIST - which is a real extension point rather than a seam
 * cut for testing: a site running a banner that is not on the list can register
 * it the same way.
 *
 * The Cookiebot class below is declared unconditionally. It stands for a banner
 * that is present and cannot be read, which is the state the settings screen
 * has to report rather than guess around.
 */

final class ConsentStubs
{
    /** @var array<string, bool> category => granted, as the WP Consent API would answer */
    public static array $consentApi = [];

    /** @var array<string, bool> the same, as Complianz would answer */
    public static array $complianz = [];

    /**
     * Which providers this imaginary site has installed.
     *
     * @var list<string>
     */
    public static array $installed = [];

    public static function reset(): void
    {
        self::$consentApi = [];
        self::$complianz = [];
        self::$installed = [];
    }

    /**
     * Make the banner list describe THIS imaginary site.
     *
     * The functions below are declared unconditionally, because PHP cannot
     * undefine one - so without this every test would look like a site with a
     * consent plugin that has granted nothing, and nothing would ever be
     * measured. A readable banner is therefore only readable when
     * `$installed` says the site has it.
     *
     * Banners that cannot be read are left alone: they are detected by class,
     * and that detection is real.
     *
     * @param array<string, array{label: string, ask: string|null, present: string|null}> $banners
     *
     * @return array<string, array{label: string, ask: string|null, present: string|null}>
     */
    public static function filterBanners(array $banners): array
    {
        foreach ($banners as $id => $banner) {
            if ($banner['ask'] !== null && !in_array($id, self::$installed, true)) {
                $banners[$id]['ask'] = null;
            }
        }

        return $banners;
    }
}

if (!function_exists('wp_has_consent')) {
    /**
     * The WP Consent API's question, as several banners implement it.
     */
    function wp_has_consent(string $category): bool
    {
        return ConsentStubs::$consentApi[$category] ?? false;
    }
}

if (!function_exists('cmplz_has_consent')) {
    function cmplz_has_consent(string $category): bool
    {
        return ConsentStubs::$complianz[$category] ?? false;
    }
}

if (!class_exists('Cookiebot_WP')) {
    /**
     * A banner that is installed and that this plugin deliberately does not try
     * to read. Detected and named; never guessed at.
     */
    class Cookiebot_WP
    {
    }
}
