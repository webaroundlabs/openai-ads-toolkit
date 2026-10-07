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
     * The Conversions API key is server-side only, and the code that puts
     * something in the page head is the likeliest place for it to escape. This
     * makes that impossible to do by accident - and now covers more than it did
     * when it read markup: handles, srcs and every inline block.
     */
    #[Test]
    public function the_enqueued_pixel_never_contains_the_conversions_api_key(): void
    {
        $this->enqueue();

        $everything = self::everything();

        self::assertStringNotContainsString('secret-key-must-never-be-rendered', $everything);
        self::assertStringNotContainsString('Bearer', $everything);
        self::assertStringNotContainsString('capi', strtolower($everything));
    }

    #[Test]
    public function it_enqueues_the_official_sdk_and_queues_init_before_it(): void
    {
        self::assertSame('', $this->enqueue(), 'The Pixel goes on the queue, it does not echo.');

        $script = self::script(Pixel::HANDLE);

        self::assertSame('https://bzrcdn.openai.com/sdk/oaiq.min.js', $script['src']);
        self::assertTrue($script['enqueued']);
        self::assertSame(['strategy' => 'async', 'in_footer' => false], $script['args']);
        self::assertNull($script['ver'], 'Our version does not belong on OpenAI\'s URL.');

        $before = self::inline(Pixel::HANDLE);

        self::assertStringContainsString('w.oaiq = q;', $before);
        self::assertStringContainsString('oaiq("init"', $before);
        self::assertStringContainsString('px-1', $before);
        self::assertLessThan(
            strpos($before, 'oaiq("init"'),
            strpos($before, 'w.oaiq = q;'),
            'The queue stub has to exist before anything calls into it.',
        );

        // The regression guard for the reason both blocks are 'before': a handle
        // carrying an 'after' inline script is ineligible for a delayed strategy,
        // and WordPress answers that by moving the script to the footer. Tidying
        // init into 'after' would silently cost the async attribute and take
        // OpenAI's SDK out of the head.
        self::assertSame([], $script['after']);
    }

    /**
     * The SDK reports only its own initialization on init. Without an explicit
     * measure call a site with the Pixel on sends no page_viewed at all - which
     * is what a live install showed in Ads Manager's event stream.
     */
    #[Test]
    public function it_measures_a_page_view_after_init(): void
    {
        $this->enqueue();

        $before = self::inline(Pixel::HANDLE);

        self::assertStringContainsString('oaiq("measure", "page_viewed", {"type":"contents"});', $before);
        self::assertGreaterThan(
            strpos($before, 'oaiq("init"'),
            strpos($before, 'oaiq("measure", "page_viewed"'),
            'The SDK replays the queue in order, and a measure before init has no pixel to go to.',
        );

        // A page cache serves this HTML to every visitor, so an id minted here
        // would be shared by all of them and deduplicate their views into one.
        self::assertStringNotContainsString('event_id', $before);
    }

    #[Test]
    public function it_enqueues_nothing_without_a_pixel_id(): void
    {
        WpStubs::$options[Settings::OPTION]['pixel_id'] = '';

        $this->enqueue();

        self::assertSame([], WpStubs::$scripts);
    }

    #[Test]
    public function it_enqueues_nothing_when_the_pixel_is_switched_off(): void
    {
        WpStubs::$options[Settings::OPTION]['pixel_enabled'] = false;

        $this->enqueue();

        self::assertSame([], WpStubs::$scripts);
    }

    /** A refused consent means the SDK is never requested at all. */
    #[Test]
    public function it_enqueues_nothing_when_consent_is_refused(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => false);

        $this->enqueue();

        self::assertSame([], WpStubs::$scripts);
    }

    /**
     * The filter takes RAW values and the server hashes them, so a page can
     * carry identity without a raw email address ever reaching browser code.
     */
    #[Test]
    public function raw_identity_supplied_through_the_filter_is_hashed_before_it_is_queued(): void
    {
        \add_filter('openai_ads_pixel_identity', static fn (): array => ['email' => ' Ada@Example.COM ']);

        $this->enqueue();

        $before = self::inline(Pixel::HANDLE);

        self::assertStringContainsString(
            'b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72',
            $before,
        );
        self::assertStringContainsString('email_sha256', $before);
        self::assertStringNotContainsString('ada@example.com', strtolower($before));
    }

    /**
     * An unusable field costs only itself. The Pixel still loads, and the rest
     * of the identity still matches.
     */
    #[Test]
    public function an_unusable_identity_field_does_not_stop_the_pixel_loading(): void
    {
        \add_filter('openai_ads_pixel_identity', static fn (): array => [
            'email' => 'ada@example.com',
            'country' => 'Romania',
        ]);

        $this->enqueue();

        $before = self::inline(Pixel::HANDLE);

        self::assertStringContainsString('email_sha256', $before);
        self::assertStringNotContainsString('Romania', $before);
    }

    /**
     * Values go through wp_json_encode, which escapes for a script context.
     * Concatenating them would let a stored value close the script tag.
     *
     * It is also what keeps us on the right side of wp_add_inline_script(),
     * which calls _doing_it_wrong() and drops the whole block when the data
     * contains a literal closing script tag - so an unescaped value would not
     * merely be an injection, it would silently delete the init call.
     */
    #[Test]
    public function identity_values_cannot_break_out_of_the_script_tag(): void
    {
        \add_filter('openai_ads_pixel_identity', static fn (): array => [
            'city' => '</script><script>alert(1)</script>',
        ]);

        $this->enqueue();

        $before = self::inline(Pixel::HANDLE);

        self::assertStringNotContainsString('</script><script>alert(1)', $before);
        self::assertStringNotContainsString('</script', $before);
    }

    #[Test]
    public function a_confirmed_conversion_can_emit_its_browser_half_with_a_shared_event_id(): void
    {
        // The deduplication bridge: same event id on both sides.
        $this->event('lead_created', 'lead_9', ['type' => 'customer_action']);

        $script = self::script(Pixel::EVENTS_HANDLE);

        self::assertFalse($script['src'], 'The events handle exists to carry inline code, nothing else.');
        self::assertSame(['in_footer' => true], $script['args']);
        self::assertTrue($script['enqueued']);

        // No dependency on the Pixel handle on purpose: eligibility for a
        // delayed strategy recurses over a handle's dependents, so a blocking
        // dependent would take the async attribute off the SDK.
        self::assertSame([], $script['deps']);

        $after = self::inline(Pixel::EVENTS_HANDLE, 'after');

        self::assertStringContainsString('oaiq("measure"', $after);
        self::assertStringContainsString('lead_created', $after);
        self::assertStringContainsString('lead_9', $after);
        self::assertStringContainsString('event_id', $after);
    }

    #[Test]
    public function a_browser_event_is_guarded_against_a_missing_sdk(): void
    {
        $this->event('lead_created', 'lead_9');

        self::assertStringContainsString('window.oaiq &&', self::inline(Pixel::EVENTS_HANDLE, 'after'));
    }

    /**
     * A conversion confirmed past the footer is still worth more than a tidy
     * queue: nothing added to a flushed queue would ever be printed.
     */
    #[Test]
    public function a_browser_event_fired_after_the_footer_was_printed_is_still_emitted(): void
    {
        WpStubs::$didAction['wp_print_footer_scripts'] = 1;

        $printed = $this->capture(static fn (Pixel $pixel) => $pixel->enqueueEvent('lead_created', 'lead_9'));

        self::assertArrayNotHasKey(Pixel::EVENTS_HANDLE, WpStubs::$scripts);
        self::assertCount(1, WpStubs::$printedScripts);
        self::assertStringContainsString('oaiq("measure"', WpStubs::$printedScripts[0]);
        self::assertStringContainsString('lead_9', $printed);
    }

    #[Test]
    public function a_browser_event_enqueues_nothing_when_consent_is_refused(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => false);

        $this->event('lead_created', 'lead_9');

        self::assertSame([], WpStubs::$scripts);
        self::assertSame([], WpStubs::$printedScripts);
    }

    /** Two conversions on one page stay two, in the order they happened. */
    #[Test]
    public function two_events_on_one_page_keep_their_order(): void
    {
        $this->event('lead_created', 'lead_9');
        $this->event('order_created', 'wc_77');

        $script = self::script(Pixel::EVENTS_HANDLE);

        self::assertCount(2, $script['after']);
        self::assertStringContainsString('lead_9', $script['after'][0]);
        self::assertStringContainsString('wc_77', $script['after'][1]);
    }

    /** @param array<string, mixed> $data */
    private function event(string $eventName, string $eventId, array $data = []): void
    {
        $this->capture(static fn (Pixel $pixel) => $pixel->enqueueEvent($eventName, $eventId, $data));
    }

    /** Runs the head Pixel and returns whatever it printed, which should be nothing. */
    private function enqueue(): string
    {
        return $this->capture(static fn (Pixel $pixel) => $pixel->enqueue());
    }

    private function capture(callable $callback): string
    {
        $settings = new Settings();
        $pixel = new Pixel($settings, new Measurement($settings));

        ob_start();
        $callback($pixel);

        return (string) ob_get_clean();
    }

    /**
     * Everything the plugin handed WordPress this request.
     *
     * @return string
     */
    private static function everything(): string
    {
        $parts = [];

        foreach (WpStubs::$scripts as $handle => $script) {
            $parts[] = $handle;
            $parts[] = is_string($script['src']) ? $script['src'] : '';
            $parts[] = implode(' ', $script['deps']);
            $parts[] = implode("\n", $script['before']);
            $parts[] = implode("\n", $script['after']);
        }

        return implode("\n", array_merge($parts, WpStubs::$printedScripts));
    }

    /** The inline code queued on one handle, in the order it will print. */
    private static function inline(string $handle, string $position = 'before'): string
    {
        $script = self::script($handle);

        return implode("\n", $position === 'before' ? $script['before'] : $script['after']);
    }

    /**
     * @return array{src: string|false, deps: list<string>, ver: mixed, args: mixed, enqueued: bool, before: list<string>, after: list<string>}
     */
    private static function script(string $handle): array
    {
        if (!isset(WpStubs::$scripts[$handle])) {
            self::fail(sprintf('Nothing was registered under "%s".', $handle));
        }

        return WpStubs::$scripts[$handle];
    }
}
