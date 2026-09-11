<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use WebaroundLabs\OpenAIAds\Laravel\Measurement;

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
     * The directive takes raw identity and hashes it on the server, which is the
     * whole reason an application does not have to hash by hand - and therefore
     * cannot get it wrong and ship a raw address in page source.
     */
    #[Test]
    public function the_directive_compiles_to_a_call_that_hashes_before_rendering(): void
    {
        $compiled = Blade::compileString("@openaiAdsPixel(['email' => \$email])");

        self::assertStringContainsString('pixelUser(', $compiled);
        self::assertStringContainsString(Measurement::class, $compiled);
    }

    #[Test]
    public function raw_identity_is_hashed_and_never_rendered_raw(): void
    {
        $user = $this->measurement()->pixelUser(['email' => ' Ada@Example.COM ']);

        self::assertSame(
            ['email_sha256' => 'b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $user,
        );

        $html = $this->render($user);

        self::assertStringNotContainsString('ada@example.com', strtolower($html));
    }

    /**
     * A page must render even when a stored value turns out to be unusable. The
     * offending field is dropped; the rest still matches.
     */
    #[Test]
    public function an_unusable_identity_field_does_not_break_the_page(): void
    {
        $user = $this->measurement()->pixelUser([
            'email' => 'ada@example.com',
            'phone' => '+1 (555) 123-4567 ext. 89',
        ]);

        self::assertArrayHasKey('email_sha256', $user);
        self::assertArrayNotHasKey('phone_number_sha256', $user);
    }

    #[Test]
    public function no_identity_is_hashed_when_the_pixel_is_switched_off(): void
    {
        config(['openai-ads.pixel_enabled' => false]);

        self::assertSame([], $this->measurement()->pixelUser(['email' => 'ada@example.com']));
    }

    private function measurement(): Measurement
    {
        return $this->app->make(Measurement::class);
    }

    /**
     * @param array<string, string> $user
     */
    private function render(array $user = []): string
    {
        return view('openai-ads::pixel', ['user' => $user])->render();
    }
}
