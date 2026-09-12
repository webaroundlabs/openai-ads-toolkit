<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use ConsentStubs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Consent;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

/**
 * Finding the site's own consent mechanism instead of demanding it be wired.
 *
 * The reason this class exists rather than a filter alone: a plugin installed
 * from the directory cannot assume its owner writes PHP. Most will not, the
 * default is to measure, and that is the wrong way round to be wrong.
 *
 * `wp_has_consent` and `cmplz_has_consent` are declared by the bootstrap for
 * these tests, which is what a site with those plugins has.
 */
#[CoversClass(Consent::class)]
final class ConsentTest extends TestCase
{
    protected function setUp(): void
    {
        // WpStubs::reset() also restores "this site has no consent plugin",
        // because PHP cannot undefine the functions the doubles declare. See
        // ConsentStubs::filterBanners().
        WpStubs::reset();
        ConsentStubs::reset();
    }

    /**
     * @param array<string, bool> $answers
     */
    private function install(string $provider, array $answers): void
    {
        ConsentStubs::$installed[] = $provider;

        if ($provider === 'wp_consent_api') {
            ConsentStubs::$consentApi = $answers;
        } else {
            ConsentStubs::$complianz = $answers;
        }
    }

    #[Test]
    public function it_asks_the_wp_consent_api_when_the_site_has_one(): void
    {
        $this->install('wp_consent_api', ['marketing' => true]);

        self::assertSame('wp_consent_api', $this->consent()->activeProvider());
        self::assertTrue($this->consent()->granted());
    }

    /**
     * The whole point. A visitor who refused marketing cookies is not measured,
     * and nobody had to write a line of PHP for that to be true.
     */
    #[Test]
    public function a_refusal_from_the_consent_plugin_stops_measurement(): void
    {
        $this->install('wp_consent_api', ['marketing' => false]);

        self::assertFalse($this->consent()->granted());
    }

    /**
     * Measurement for advertising is marketing, not statistics. Asking for the
     * statistics category would collect under a permission the visitor did not
     * give for this.
     */
    #[Test]
    public function it_asks_for_the_marketing_category_specifically(): void
    {
        $this->install('wp_consent_api', ['statistics' => true, 'marketing' => false]);

        self::assertFalse($this->consent()->granted());
    }

    #[Test]
    public function complianz_is_read_directly_when_the_shared_standard_is_absent(): void
    {
        $this->install('complianz', ['marketing' => true]);

        self::assertSame('complianz', $this->consent()->activeProvider());
        self::assertTrue($this->consent()->granted());
    }

    /**
     * A vendor's own function is the thing more likely to change underneath, so
     * the shared standard is preferred where both are present.
     */
    #[Test]
    public function the_shared_standard_wins_over_a_vendors_own_function(): void
    {
        $this->install('wp_consent_api', ['marketing' => true]);
        $this->install('complianz', ['marketing' => false]);

        self::assertSame('wp_consent_api', $this->consent()->activeProvider());
        self::assertTrue($this->consent()->granted());
    }

    #[Test]
    public function a_site_with_two_providers_can_pin_the_one_it_means(): void
    {
        $this->install('wp_consent_api', ['marketing' => true]);
        $this->install('complianz', ['marketing' => false]);
        WpStubs::$options[Settings::OPTION] = ['consent_mode' => 'complianz'];

        self::assertSame('complianz', $this->consent()->activeProvider());
        self::assertFalse($this->consent()->granted());
    }

    // ------------------------------------------------------- the default case

    /**
     * The uncomfortable default, and it is deliberate.
     *
     * Defaulting to "no" would make the plugin silently measure nothing on any
     * site without a banner, and the owner would hunt for the fault for days.
     * The settings screen carries a warning instead - see isUngated().
     */
    #[Test]
    public function with_no_provider_and_no_filter_it_measures_and_says_so(): void
    {
        self::assertNull($this->consent()->activeProvider());
        self::assertTrue($this->consent()->granted());
        self::assertTrue($this->consent()->isUngated(), 'The settings screen must warn about this.');
    }

    #[Test]
    public function a_detected_provider_means_the_site_is_not_ungated(): void
    {
        $this->install('wp_consent_api', ['marketing' => true]);

        self::assertFalse($this->consent()->isUngated());
    }

    #[Test]
    public function a_site_that_wired_the_filter_is_not_warned_at_all(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => true);

        self::assertFalse($this->consent()->isUngated());
    }

    #[Test]
    public function choosing_a_mode_deliberately_is_never_a_warning(): void
    {
        foreach ([Consent::MODE_OFF, Consent::MODE_FILTER] as $mode) {
            WpStubs::$options[Settings::OPTION] = ['consent_mode' => $mode];

            self::assertFalse($this->consent()->isUngated(), $mode);
        }
    }

    // ------------------------------------------------------------ the filter

    /** The filter runs last and overrides anything decided here. */
    #[Test]
    public function the_filter_overrides_a_detected_provider(): void
    {
        $this->install('wp_consent_api', ['marketing' => true]);
        \add_filter('openai_ads_consent', static fn (): bool => false);

        self::assertFalse($this->consent()->granted());
    }

    #[Test]
    public function the_filter_still_runs_when_gating_is_switched_off(): void
    {
        WpStubs::$options[Settings::OPTION] = ['consent_mode' => Consent::MODE_OFF];
        \add_filter('openai_ads_consent', static fn (): bool => false);

        self::assertFalse($this->consent()->granted());
    }

    #[Test]
    public function filter_mode_ignores_a_present_provider_and_defers_to_the_site(): void
    {
        $this->install('wp_consent_api', ['marketing' => false]);
        WpStubs::$options[Settings::OPTION] = ['consent_mode' => Consent::MODE_FILTER];

        // The provider said no; the site said it answers for itself and has not
        // objected, so measurement proceeds.
        self::assertTrue($this->consent()->granted());
    }

    // -------------------------------------------------------- known banners

    /**
     * Guessing at a banner's internals would produce a consent check that
     * answers confidently and wrongly. Detecting it and saying so does not.
     */
    #[Test]
    public function a_banner_it_cannot_read_is_reported_rather_than_guessed_at(): void
    {
        $consent = $this->consent();

        self::assertSame(['cookiebot' => 'Cookiebot'], $consent->unreadableBanners());
        self::assertNull($consent->activeProvider(), 'It must not pretend to read it.');
    }

    #[Test]
    public function a_pinned_provider_that_was_deactivated_falls_back_to_none(): void
    {
        WpStubs::$options[Settings::OPTION] = ['consent_mode' => 'complianz'];

        self::assertNull($this->consent()->activeProvider());
        self::assertTrue($this->consent()->granted());
    }

    private function consent(): Consent
    {
        return new Consent(new Settings());
    }
}
