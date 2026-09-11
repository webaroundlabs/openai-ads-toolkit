<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\FluentForms;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\GravityForms;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\LeadRecorder;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\NinjaForms;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\UserRegistration;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\WPForms;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

/**
 * The form plugins that report server-side only.
 *
 * Each one's job is the same and small: find the boundary the host confirms,
 * pull identity out of whatever shape that host hands over, and report once.
 * These assert the second part, because it is the only part that differs, and
 * because getting it wrong is silent - an email that is not recognized is simply
 * a conversion that matches nobody.
 *
 * The field shapes below are the ones each plugin documents. Nothing here proves
 * the hook name is right; only a site with the plugin installed can do that.
 */
#[CoversClass(GravityForms::class)]
#[CoversClass(WPForms::class)]
#[CoversClass(FluentForms::class)]
#[CoversClass(NinjaForms::class)]
#[CoversClass(UserRegistration::class)]
final class NewIntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        Plugin::reset();
        WpStubs::$options[Settings::OPTION] = [
            'pixel_id' => 'px-1',
            'capi_key' => 'secret',
            'use_scheduler' => false,
        ];
    }

    protected function tearDown(): void
    {
        Plugin::reset();
    }

    /**
     * Gravity Forms keeps a name field's parts under sub-ids - `.3` first, `.6`
     * last - and nothing at the parent id. Reading the parent would find an
     * empty string and report no name at all.
     */
    #[Test]
    public function gravity_forms_reads_identity_including_a_split_name_field(): void
    {
        $integration = new GravityForms(new LeadRecorder($this->plugin()));

        $integration->onSubmission(
            [
                '1' => ' Ada@Example.COM ',
                '2' => '+40 746 123 456',
                '3.3' => 'Ion',
                '3.6' => 'Ștefănescu',
            ],
            [
                'id' => 7,
                'title' => 'Contact',
                'fields' => [
                    (object) ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                    (object) ['id' => 2, 'type' => 'phone', 'label' => 'Phone'],
                    (object) ['id' => 3, 'type' => 'name', 'label' => 'Name'],
                ],
            ],
        );

        $user = $this->lastEvent()['user'];

        self::assertSame(
            ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $user['emails_sha256'],
        );
        self::assertArrayHasKey('phone_numbers_sha256', $user);
        self::assertArrayHasKey('first_names_sha256', $user);
        self::assertSame(
            ['1c43886f1047ed5dfae59c0945ff61475b7df3a912312a6484ce80429df049a1'],
            $user['last_names_sha256'],
        );
    }

    /** WPForms flattens a name field into `first` and `last` on the field itself. */
    #[Test]
    public function wpforms_reads_identity_from_its_flattened_fields(): void
    {
        $integration = new WPForms(new LeadRecorder($this->plugin()));

        $integration->onComplete(
            [
                1 => ['name' => 'Email', 'type' => 'email', 'value' => 'ada@example.com'],
                2 => ['name' => 'Name', 'type' => 'name', 'first' => 'Ion', 'last' => 'Popescu'],
                3 => ['name' => 'Message', 'type' => 'textarea', 'value' => 'Hello'],
            ],
            [],
            ['id' => 12, 'settings' => ['form_title' => 'Contact']],
        );

        $user = $this->lastEvent()['user'];

        self::assertArrayHasKey('emails_sha256', $user);
        self::assertArrayHasKey('first_names_sha256', $user);
        self::assertArrayHasKey('last_names_sha256', $user);
    }

    /**
     * Fluent Forms gives no type information at this point, so the field's own
     * name is the only signal - and its composite name field arrives as parts.
     */
    #[Test]
    public function fluent_forms_reads_identity_by_field_name(): void
    {
        $integration = new FluentForms(new LeadRecorder($this->plugin()));

        $integration->onSubmission(
            99,
            [
                'email' => 'ada@example.com',
                'phone' => '+40 746 123 456',
                'names' => ['first_name' => 'Ion', 'last_name' => 'Popescu'],
                'message' => 'Hello',
            ],
            (object) ['id' => 3, 'title' => 'Contact'],
        );

        $user = $this->lastEvent()['user'];

        self::assertArrayHasKey('emails_sha256', $user);
        self::assertArrayHasKey('phone_numbers_sha256', $user);
        self::assertArrayHasKey('first_names_sha256', $user);
        self::assertArrayHasKey('last_names_sha256', $user);
    }

    /** Ninja Forms spells the name fields as TYPES, not as field names. */
    #[Test]
    public function ninja_forms_maps_its_own_field_types(): void
    {
        $integration = new NinjaForms(new LeadRecorder($this->plugin()));

        $integration->onSubmission([
            'form_id' => 4,
            'settings' => ['title' => 'Contact'],
            'fields' => [
                1 => ['key' => 'email_1', 'type' => 'email', 'value' => 'ada@example.com'],
                2 => ['key' => 'firstname_1', 'type' => 'firstname', 'value' => 'Ion'],
                3 => ['key' => 'lastname_1', 'type' => 'lastname', 'value' => 'Popescu'],
            ],
        ]);

        $user = $this->lastEvent()['user'];

        self::assertArrayHasKey('emails_sha256', $user);
        self::assertArrayHasKey('first_names_sha256', $user);
        self::assertArrayHasKey('last_names_sha256', $user);
    }

    /**
     * A form the extraction cannot read is still a conversion. Reporting it
     * without identity is worth more than not reporting it - the event still
     * counts, it just matches on attribution alone.
     */
    #[Test]
    public function a_form_with_unrecognizable_fields_still_reports_the_conversion(): void
    {
        $integration = new NinjaForms(new LeadRecorder($this->plugin()));

        $integration->onSubmission([
            'form_id' => 4,
            'fields' => [1 => ['key' => 'q1', 'type' => 'textbox', 'value' => 'Something']],
        ]);

        self::assertSame('lead_created', $this->lastEvent()['type']);
    }

    // ------------------------------------------------- WordPress registration

    #[Test]
    public function a_front_end_registration_is_reported_with_a_stable_event_id(): void
    {
        WpStubs::$users[7] = (object) [
            'user_email' => 'ada@example.com',
            'first_name' => 'Ion',
            'last_name' => 'Popescu',
        ];

        (new UserRegistration($this->plugin()))->onUserRegistered(7);

        $event = $this->lastEvent();

        self::assertSame('registration_completed', $event['type']);
        // Stable and business-owned, so re-running whatever created the account
        // reports the same conversion rather than a second one.
        self::assertSame('wp_user_7', $event['id']);
        self::assertArrayHasKey('emails_sha256', $event['user']);
        self::assertArrayHasKey('external_ids_sha256', $event['user']);
    }

    /**
     * The reason this integration is off by default. `user_register` fires for
     * an administrator adding a colleague and for an importer restoring a
     * backup; counting those inflates the number the advertiser optimizes
     * against, in the direction nothing downstream flags.
     */
    #[Test]
    public function an_administrative_registration_is_not_a_conversion(): void
    {
        WpStubs::$isAdmin = true;
        WpStubs::$users[7] = (object) ['user_email' => 'colleague@example.com'];

        (new UserRegistration($this->plugin()))->onUserRegistered(7);

        self::assertNull(Plugin::instance()?->measurement()->flush());
        self::assertSame([], WpStubs::$requests);
    }

    #[Test]
    public function a_site_can_refine_which_registrations_count(): void
    {
        WpStubs::$users[7] = (object) ['user_email' => 'ada@example.com'];
        \add_filter('openai_ads_should_report_registration', static fn (): bool => false);

        (new UserRegistration($this->plugin()))->onUserRegistered(7);

        self::assertSame([], WpStubs::$requests);
        self::assertNull(Plugin::instance()?->measurement()->flush());
    }

    private function plugin(): Plugin
    {
        return Plugin::boot(__DIR__ . '/../openai-ads.php', '0.1.0');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(): array
    {
        Plugin::instance()?->measurement()->flush();

        self::assertNotSame([], WpStubs::$requests, 'Nothing was sent.');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);

        /** @var array<string, mixed> $event */
        $event = $body['events'][0];

        return $event;
    }
}
