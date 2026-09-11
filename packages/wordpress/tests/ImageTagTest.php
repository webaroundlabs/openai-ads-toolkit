<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

/**
 * The no-JavaScript channel, as the plugin exposes it.
 *
 * It exists for the places a script cannot go - an email body, an AMP page, a
 * <noscript> fallback - and it earns its place by carrying the same event id as
 * the server event, so the two deduplicate.
 */
#[CoversClass(Plugin::class)]
final class ImageTagTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        Plugin::reset();
        WpStubs::$options[Settings::OPTION] = [
            'pixel_id' => 'px-1',
            'capi_key' => 'secret-key-must-never-be-rendered',
        ];
    }

    protected function tearDown(): void
    {
        Plugin::reset();
    }

    #[Test]
    public function it_builds_a_url_carrying_the_caller_supplied_event_id(): void
    {
        $url = $this->plugin()->imageTagUrl('lead_created', [], ['event_id' => 'lead-9']);

        self::assertNotNull($url);
        self::assertStringStartsWith('https://bzr.openai.com/v1/sdk/events?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('px-1', $query['pid']);
        self::assertSame('lead_created', $query['event']);
        self::assertSame('lead-9', $query['event_id']);
        self::assertSame('customer_action', $query['data']['type']);
    }

    /**
     * The Conversions API key must never reach a URL that is printed into a
     * page, an email, or a server log along the way.
     */
    #[Test]
    public function the_url_never_contains_the_conversions_api_key(): void
    {
        $url = (string) $this->plugin()->imageTagUrl('lead_created', [], ['event_id' => 'lead-9']);

        self::assertStringNotContainsString('secret-key-must-never-be-rendered', $url);
    }

    /**
     * OpenAI documents no user object for this channel and forbids personal data
     * in a query parameter. The plugin always has identity to hand - the obref
     * cookie, the IP, the user agent - so dropping it has to be deliberate.
     */
    #[Test]
    public function identity_never_reaches_the_url(): void
    {
        $url = (string) $this->plugin()->imageTagUrl('lead_created', [], [
            'event_id' => 'lead-9',
            'user' => ['email' => 'ada@example.com'],
        ]);

        $decoded = urldecode($url);

        self::assertStringNotContainsString('ada@example.com', $decoded);
        self::assertStringNotContainsString('emails_sha256', $decoded);
        self::assertStringNotContainsString('email_sha256', $decoded);
        self::assertStringNotContainsString('ip_address', $decoded);
    }

    #[Test]
    public function it_measures_nothing_when_consent_is_refused(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => false);

        self::assertNull($this->plugin()->imageTagUrl('lead_created', [], ['event_id' => 'lead-9']));
    }

    #[Test]
    public function an_unknown_event_returns_null_rather_than_throwing(): void
    {
        self::assertNull($this->plugin()->imageTagUrl('not_an_event', [], ['event_id' => 'x']));
    }

    #[Test]
    public function the_printed_element_is_escaped(): void
    {
        $this->plugin();

        ob_start();
        \openai_ads_image_tag('order_created', ['amount' => 2599, 'currency' => 'EUR'], [
            'event_id' => 'order&9<script>',
        ]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<img src="https://bzr.openai.com/v1/sdk/events?', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    private function plugin(): Plugin
    {
        return Plugin::boot(__DIR__ . '/../openai-ads.php', '0.1.0');
    }
}
