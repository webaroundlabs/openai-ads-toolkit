<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

#[CoversClass(Settings::class)]
final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
    }

    #[Test]
    public function it_reads_stored_values(): void
    {
        $this->store(['pixel_id' => ' px-1 ', 'capi_key' => 'secret']);

        $settings = new Settings();

        self::assertSame('px-1', $settings->pixelId());
        self::assertSame('secret', $settings->capiKey());
    }

    #[Test]
    public function measurement_is_off_until_both_credentials_exist(): void
    {
        $this->store(['pixel_id' => 'px-1']);

        self::assertFalse((new Settings())->capiEnabled());
    }

    #[Test]
    public function the_pixel_can_run_without_a_conversions_api_key(): void
    {
        // A site may use the browser Pixel alone.
        $this->store(['pixel_id' => 'px-1']);

        self::assertTrue((new Settings())->pixelEnabled());
    }

    #[Test]
    public function switches_default_sensibly_on_a_fresh_install(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->validateOnly());
        self::assertFalse($settings->debug());
        self::assertTrue($settings->stripQueryString());
        self::assertSame('webaroundlabs-wordpress', $settings->integrationSource());
    }

    /**
     * The safer place for a secret: out of the database, so it does not travel
     * in a backup or a staging clone, and out of reach of anyone who can edit
     * options but not files.
     */
    #[Test]
    public function a_key_defined_in_wp_config_wins_over_the_database(): void
    {
        $this->store(['capi_key' => 'from-database']);
        define(Settings::KEY_CONSTANT, 'from-wp-config');

        $settings = new Settings();

        self::assertSame('from-wp-config', $settings->capiKey());
        self::assertTrue($settings->capiKeyIsConstant());
    }

    #[Test]
    public function saving_with_a_blank_key_field_keeps_the_existing_key(): void
    {
        // Otherwise every save from the settings screen would wipe the secret.
        $this->store(['capi_key' => 'already-saved']);
        $settings = new Settings();

        $result = $settings->sanitize([
            Settings::FIELDS_PRESENT => ['pixel_id', 'capi_key'],
            'pixel_id' => 'px-1',
            'capi_key' => '',
        ]);

        self::assertSame('already-saved', $result['capi_key']);
    }

    #[Test]
    public function submitted_values_are_sanitized(): void
    {
        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['pixel_id', 'timeout'],
            'pixel_id' => "  px-1<script>alert(1)</script>\n",
            'timeout' => '900',
        ]);

        self::assertStringNotContainsString('<script>', $result['pixel_id']);
        self::assertSame(30, $result['timeout'], 'Timeout is clamped to something sane.');
    }

    #[Test]
    public function an_invalid_integration_source_falls_back_to_the_default(): void
    {
        // An invalid value would make every API request fail, so it is replaced
        // rather than stored.
        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['integration_source'],
            'integration_source' => 'not valid!',
        ]);

        self::assertSame('webaroundlabs-wordpress', $result['integration_source']);
    }

    #[Test]
    public function a_non_http_canonical_origin_is_rejected(): void
    {
        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['canonical_origin'],
            'canonical_origin' => 'javascript:alert(1)',
        ]);

        self::assertSame('', $result['canonical_origin']);
    }

    #[Test]
    public function the_canonical_origin_defaults_to_the_site_and_is_normalized(): void
    {
        $this->store(['canonical_origin' => 'https://SHOP.example.com/some/path']);

        self::assertSame('https://shop.example.com', (new Settings())->canonicalOrigin());
    }

    /**
     * An unchecked checkbox is absent from the POST body entirely, so absence
     * means off. An explicit "0" must also mean off - PHP treats the string "0"
     * as empty, which is the behaviour we want here and worth pinning, because
     * reading it as "present, therefore on" would silently enable settings a
     * site owner turned off.
     */
    #[Test]
    public function checkboxes_become_booleans(): void
    {
        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['pixel_enabled', 'debug', 'capi_enabled'],
            'pixel_enabled' => '1',
            'debug' => '0',
        ]);

        self::assertTrue($result['pixel_enabled'], 'A checked box sends "1".');
        self::assertFalse($result['debug'], 'An explicit "0" means off.');
        self::assertFalse($result['capi_enabled'], 'An absent box means off.');
    }

    /**
     * The settings live in one option row but are edited across several
     * screens. A field on another screen is absent from a POST body in exactly
     * the way an unchecked checkbox is, so without the form saying what it
     * covered, saving Integrations would read "no Pixel ID was submitted" as
     * "the Pixel ID is now empty" - and the site would stop measuring because
     * somebody ticked a box on a different page.
     */
    #[Test]
    public function a_form_does_not_touch_the_settings_it_never_rendered(): void
    {
        $this->store([
            'pixel_id' => 'px-1',
            'capi_key' => 'already-saved',
            'capi_enabled' => true,
            'consent_mode' => 'complianz',
        ]);

        // What the Integrations screen posts: its own field, and nothing else.
        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['consent_mode'],
            'consent_mode' => 'off',
        ]);

        self::assertSame('off', $result['consent_mode'], 'The submitted field is saved.');
        self::assertSame('px-1', $result['pixel_id']);
        self::assertSame('already-saved', $result['capi_key']);
        self::assertTrue($result['capi_enabled'], 'An absent checkbox on another screen is not "off".');
    }

    /**
     * The same protection, for a field the screen itself decided not to show.
     * Deferred delivery is only rendered where Action Scheduler exists, so on a
     * site without WooCommerce every save used to write false over it - and the
     * day WooCommerce arrived, durable delivery stayed off.
     */
    #[Test]
    public function a_field_the_screen_hid_keeps_its_stored_value(): void
    {
        $this->store(['use_scheduler' => true]);

        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['pixel_id'],
            'pixel_id' => 'px-1',
        ]);

        self::assertTrue($result['use_scheduler']);
    }

    /** Nothing rendered, nothing written. The safe direction for stored data. */
    #[Test]
    public function a_form_that_declares_nothing_changes_nothing(): void
    {
        $this->store(['pixel_id' => 'px-1', 'debug' => true]);

        $result = (new Settings())->sanitize(['pixel_id' => 'wiped', 'debug' => '']);

        self::assertSame('px-1', $result['pixel_id']);
        self::assertTrue($result['debug']);
    }

    /** A declaration is a list of names, and arrives from a form like anything else. */
    #[Test]
    public function a_tampered_declaration_is_ignored_rather_than_obeyed(): void
    {
        $this->store(['pixel_id' => 'px-1']);

        $result = (new Settings())->sanitize([
            Settings::FIELDS_PRESENT => ['pixel_id<script>', ['nested'], 'PIXEL_ID'],
            'pixel_id' => 'wiped',
        ]);

        self::assertSame('px-1', $result['pixel_id']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function store(array $values): void
    {
        WpStubs::$options[Settings::OPTION] = $values;
    }
}
