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

    #[Test]
    public function already_hashed_identity_can_be_supplied_through_a_filter(): void
    {
        $digest = str_repeat('a', 64);
        \add_filter('openai_ads_pixel_user', static fn (): array => ['email_sha256' => $digest]);

        $html = $this->render();

        self::assertStringContainsString($digest, $html);
        self::assertStringContainsString('user', $html);
    }

    /**
     * Values go through wp_json_encode, which escapes for a script context.
     * Concatenating them would let a stored value close the script tag.
     */
    #[Test]
    public function identity_values_cannot_break_out_of_the_script_tag(): void
    {
        \add_filter('openai_ads_pixel_user', static fn (): array => [
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
