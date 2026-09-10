<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;

final class PixelViewTest extends TestCase
{
    /**
     * The single most important assertion in this package.
     *
     * The Conversions API key is server-side only. A Blade template is the most
     * plausible place for it to escape into a public page, so this test exists
     * to make that impossible to do by accident.
     */
    #[Test]
    public function the_rendered_pixel_never_contains_the_conversions_api_key(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString(self::CAPI_KEY, $html);
        self::assertStringNotContainsString('capi', strtolower($html));
        self::assertStringNotContainsString('Bearer', $html);
    }

    #[Test]
    public function it_renders_the_official_loader_and_initializes_the_public_pixel_id(): void
    {
        $html = $this->render();

        self::assertStringContainsString('https://bzrcdn.openai.com/sdk/oaiq.min.js', $html);
        self::assertStringContainsString('oaiq("init"', $html);
        self::assertStringContainsString(self::PIXEL_ID, $html);
    }

    #[Test]
    public function it_renders_nothing_when_the_pixel_is_switched_off(): void
    {
        config(['openai-ads.pixel_enabled' => false]);

        self::assertSame('', trim($this->render()));
    }

    #[Test]
    public function it_renders_nothing_when_no_pixel_id_is_configured(): void
    {
        config(['openai-ads.pixel_id' => null]);

        self::assertSame('', trim($this->render()));
    }

    /** A refused consent means the SDK is never loaded at all. */
    #[Test]
    public function it_renders_nothing_when_consent_is_refused(): void
    {
        config(['openai-ads.consent' => static fn (): bool => false]);

        self::assertSame('', trim($this->render()));
    }

    #[Test]
    public function it_can_carry_already_hashed_identity(): void
    {
        $digest = str_repeat('a', 64);

        $html = $this->render(['email_sha256' => $digest]);

        self::assertStringContainsString($digest, $html);
        self::assertStringContainsString('"user"', $html);
    }

    #[Test]
    public function identity_values_are_json_encoded_rather_than_concatenated(): void
    {
        // @json escapes for a <script> context; string concatenation would not.
        $html = $this->render(['city' => '</script><script>alert(1)</script>']);

        self::assertStringNotContainsString('</script><script>alert(1)', $html);
        self::assertStringContainsString('</script', $html);
    }

    #[Test]
    public function the_pixel_directive_is_registered(): void
    {
        self::assertArrayHasKey('openaiAdsPixel', Blade::getCustomDirectives());
    }

    /**
     * @param array<string, string> $user
     */
    private function render(array $user = []): string
    {
        return view('openai-ads::pixel', ['user' => $user])->render();
    }
}
