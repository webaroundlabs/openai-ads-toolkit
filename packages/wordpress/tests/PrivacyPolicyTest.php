<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Admin\PrivacyPolicy;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

/**
 * The suggested privacy policy text.
 *
 * What is worth pinning here is not the prose - that will be edited - but the
 * four properties a site owner relies on: it is offered to WordPress at all, it
 * is translatable under this plugin's domain, it describes what the code
 * actually sends, and producing it touches nothing.
 */
#[CoversClass(PrivacyPolicy::class)]
final class PrivacyPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
    }

    #[Test]
    public function it_registers_on_admin_init_and_nowhere_else(): void
    {
        PrivacyPolicy::register();

        self::assertArrayHasKey('admin_init', WpStubs::$filters);
        self::assertCount(1, WpStubs::$filters['admin_init']);

        // Registration must not build the text, which is the point of hooking
        // rather than calling: this runs on every admin request.
        self::assertSame([], WpStubs::$privacyPolicy);
    }

    #[Test]
    public function it_offers_the_text_to_wordpress_under_the_plugin_name(): void
    {
        PrivacyPolicy::add();

        self::assertCount(1, WpStubs::$privacyPolicy);
        self::assertSame('Conversion Tracking for OpenAI Ads', WpStubs::$privacyPolicy[0][0]);
        self::assertNotSame('', WpStubs::$privacyPolicy[0][1]);
    }

    /**
     * A string translated against the wrong domain never picks up a
     * translation, and nothing about the page looks broken when it happens.
     */
    #[Test]
    public function every_string_is_translatable_under_the_plugin_text_domain(): void
    {
        PrivacyPolicy::add();

        self::assertNotEmpty(WpStubs::$translated);

        foreach (WpStubs::$translated as [$string, $domain]) {
            self::assertSame(
                'conversion-tracking-for-openai-ads',
                $domain,
                sprintf('"%s" is translated against the wrong domain.', $string),
            );
        }
    }

    /**
     * WordPress strips the tutorial paragraphs when the text is copied into the
     * policy, and keeps everything after "Suggested text". Without those two
     * markers the instructions to the site owner end up in the published policy.
     */
    #[Test]
    public function it_separates_the_instructions_from_the_suggested_text(): void
    {
        PrivacyPolicy::add();
        $content = WpStubs::$privacyPolicy[0][1];

        self::assertStringContainsString('<p class="privacy-policy-tutorial">', $content);
        self::assertStringContainsString(
            '<strong class="privacy-policy-tutorial">Suggested text:</strong>',
            $content,
        );
    }

    /**
     * The draft has to describe this plugin rather than measurement in general.
     * Each of these is a disclosure the site owner would otherwise have to read
     * the source to make: the cookies the Pixel sets, that identifiers are
     * hashed before they leave, that product names are not, that query strings
     * are dropped, and where OpenAI's own policy lives.
     */
    #[Test]
    public function it_states_what_this_plugin_actually_sends(): void
    {
        PrivacyPolicy::add();
        $content = WpStubs::$privacyPolicy[0][1];

        self::assertStringContainsString('__oppref', $content);
        self::assertStringContainsString('__obref', $content);
        self::assertStringContainsString('SHA-256', $content);
        self::assertStringContainsString('query strings', $content);
        self::assertStringContainsString('https://openai.com/policies/privacy-policy/', $content);

        // The three addresses in readme.txt are the plugin's; the policy text
        // points at OpenAI's policy and at nothing of the author's.
        self::assertStringNotContainsString('webaround.ro', $content);
    }

    /**
     * Rendering code prepares nothing and reads nothing. This one runs on every
     * admin request through admin_init, so an option read here is a query on a
     * screen that has no use for it.
     */
    #[Test]
    public function producing_the_text_reads_no_settings_and_sends_nothing(): void
    {
        WpStubs::$options[Settings::OPTION] = [
            'pixel_id' => 'px-1',
            'capi_key' => 'secret-key-must-never-be-rendered',
        ];

        PrivacyPolicy::add();

        self::assertSame([], WpStubs::$requests);
        self::assertStringNotContainsString(
            'secret-key-must-never-be-rendered',
            WpStubs::$privacyPolicy[0][1],
        );
        self::assertStringNotContainsString('px-1', WpStubs::$privacyPolicy[0][1]);
    }

    /**
     * The only markup that reaches the policy editor is the link, and it has to
     * survive wp_kses_post as a link rather than as escaped text.
     */
    #[Test]
    public function the_link_to_openais_policy_is_a_link(): void
    {
        PrivacyPolicy::add();

        self::assertStringContainsString(
            '<a href="https://openai.com/policies/privacy-policy/">',
            WpStubs::$privacyPolicy[0][1],
        );
    }
}
