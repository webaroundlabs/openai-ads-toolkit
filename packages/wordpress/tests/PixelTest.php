<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Measurement;
use WebaroundLabs\OpenAIAds\WordPress\Pixel;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

#[CoversClass(Pixel::class)]
final class PixelTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        WpStubs::$options[Settings::OPTION] = [
            'pixel_id' => 'px-1',
            'capi_key' => 'secret-key-must-never-be-rendered',
        ];
    }

    /**
     * The most important assertion in the plugin.
     *
     * The Conversions API key is server-side only, and a template that prints
     * into the page head is the likeliest place for it to escape. This makes
     * that impossible to do by accident.
     */
    #[Test]
    public function the_rendered_pixel_never_contains_the_conversions_api_key(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('secret-key-must-never-be-rendered', $html);
        self::assertStringNotContainsString('Bearer', $html);
        self::assertStringNotContainsString('capi', strtolower($html));
    }

    #[Test]
    public function it_renders_the_official_loader_and_initializes_the_public_pixel_id(): void
    {
        $html = $this->render();

        self::assertStringContainsString('https://bzrcdn.openai.com/sdk/oaiq.min.js', $html);
        self::assertStringContainsString('oaiq("init"', $html);
        self::assertStringContainsString('px-1', $html);
    }

    #[Test]
    public function it_renders_nothing_without_a_pixel_id(): void
    {
        WpStubs::$options[Settings::OPTION]['pixel_id'] = '';

        self::assertSame('', $this->render());
    }

    #[Test]
    public function it_renders_nothing_when_the_pixel_is_switched_off(): void
    {
        WpStubs::$options[Settings::OPTION]['pixel_enabled'] = false;

        self::assertSame('', $this->render());
    }

    /** A refused consent means the SDK is never loaded at all. */
    #[Test]
    public function it_renders_nothing_when_consent_is_refused(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => false);

        self::assertSame('', $this->render());
    }

    /**
     * The filter takes RAW values and the server hashes them, so a page can
     * carry identity without a raw email address ever reaching browser code.
     */
    #[Test]
    public function raw_identity_supplied_through_the_filter_is_hashed_before_it_is_printed(): void
    {
        \add_filter('openai_ads_pixel_identity', static fn (): array => ['email' => ' Ada@Example.COM ']);

        $html = $this->render();

        self::assertStringContainsString(
            'b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72',
            $html,
        );
        self::assertStringContainsString('email_sha256', $html);
        self::assertStringNotContainsString('ada@example.com', strtolower($html));
    }

    /**
     * An unusable field costs only itself. The page still renders, and the rest
     * of the identity still matches.
     */
    #[Test]
    public function an_unusable_identity_field_does_not_stop_the_pixel_rendering(): void
    {
        \add_filter('openai_ads_pixel_identity', static fn (): array => [
            'email' => 'ada@example.com',
            'country' => 'Romania',
        ]);

        $html = $this->render();

        self::assertStringContainsString('email_sha256', $html);
        self::assertStringNotContainsString('Romania', $html);
    }

    /**
     * Values go through wp_json_encode, which escapes for a script context.
     * Concatenating them would let a stored value close the script tag.
     */
    #[Test]
    public function identity_values_cannot_break_out_of_the_script_tag(): void
    {
        \add_filter('openai_ads_pixel_identity', static fn (): array => [
            'city' => '</script><script>alert(1)</script>',
        ]);

        $html = $this->render();

        self::assertStringNotContainsString('</script><script>alert(1)', $html);
    }

    #[Test]
    public function a_confirmed_conversion_can_emit_its_browser_half_with_a_shared_event_id(): void
    {
        // The deduplication bridge: same event id on both sides.
        $html = $this->capture(fn (Pixel $pixel) => $pixel->renderEvent('lead_created', 'lead_9', ['type' => 'customer_action']));

        self::assertStringContainsString('oaiq("measure"', $html);
        self::assertStringContainsString('lead_created', $html);
        self::assertStringContainsString('lead_9', $html);
        self::assertStringContainsString('event_id', $html);
    }

    #[Test]
    public function a_browser_event_is_guarded_against_a_missing_sdk(): void
    {
        $html = $this->capture(fn (Pixel $pixel) => $pixel->renderEvent('lead_created', 'lead_9'));

        self::assertStringContainsString('window.oaiq &&', $html);
    }

    private function render(): string
    {
        return $this->capture(static fn (Pixel $pixel) => $pixel->render());
    }

    private function capture(callable $callback): string
    {
        $settings = new Settings();
        $pixel = new Pixel($settings, new Measurement($settings));

        ob_start();
        $callback($pixel);

        return (string) ob_get_clean();
    }
}
