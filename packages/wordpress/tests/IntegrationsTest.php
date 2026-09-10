<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\ContactForm7;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\ElementorForms;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\LeadRecorder;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\Registry;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use Cf7SubmissionStub;
use WpStubs;

#[CoversClass(LeadRecorder::class)]
#[CoversClass(ContactForm7::class)]
#[CoversClass(ElementorForms::class)]
#[CoversClass(Registry::class)]
final class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        WpStubs::$options[Settings::OPTION] = [
            'pixel_id' => 'px-1',
            'capi_key' => 'secret',
            // These tests exercise inline delivery; the deferred path has its own suite.
            'use_scheduler' => false,
        ];
        Plugin::reset();
    }

    protected function tearDown(): void
    {
        Plugin::reset();
    }

    // ---------------------------------------------------------------- fields

    /**
     * Email and phone come from the field TYPE, which the form builder already
     * declared. Guessing from labels is how integrations end up hashing a
     * subject line as an email address.
     */
    #[Test]
    public function identity_is_taken_from_field_types_not_from_labels(): void
    {
        $user = LeadRecorder::identityFromFields([
            ['type' => 'text', 'name' => 'your-subject', 'value' => 'not-an@email.com'],
            ['type' => 'email', 'name' => 'contact', 'value' => 'ada@example.com'],
            ['type' => 'tel', 'name' => 'whatever', 'value' => '+40 746 123 456'],
        ]);

        self::assertSame('ada@example.com', $user['email']);
        self::assertSame('+40 746 123 456', $user['phone']);
    }

    #[Test]
    public function names_are_taken_only_when_a_field_is_explicitly_named_for_one(): void
    {
        $user = LeadRecorder::identityFromFields([
            ['type' => 'text', 'name' => 'first-name', 'value' => 'Ion'],
            ['type' => 'text', 'name' => 'Last Name', 'value' => 'Ștefănescu'],
        ]);

        self::assertSame('Ion', $user['first_name']);
        self::assertSame('Ștefănescu', $user['last_name']);
    }

    /**
     * A single "name" field does not split reliably - "Mary Jane Watson" has no
     * correct answer - and a wrong surname hashes to a digest that matches
     * nobody, which is worse than sending none.
     */
    #[Test]
    public function a_single_combined_name_field_is_not_guessed_at(): void
    {
        $user = LeadRecorder::identityFromFields([
            ['type' => 'text', 'name' => 'your-name', 'value' => 'Mary Jane Watson'],
        ]);

        self::assertArrayNotHasKey('first_name', $user);
        self::assertArrayNotHasKey('last_name', $user);
    }

    #[Test]
    public function empty_values_and_the_first_match_only(): void
    {
        $user = LeadRecorder::identityFromFields([
            ['type' => 'email', 'name' => 'a', 'value' => '   '],
            ['type' => 'email', 'name' => 'b', 'value' => 'first@example.com'],
            ['type' => 'email', 'name' => 'c', 'value' => 'second@example.com'],
        ]);

        self::assertSame('first@example.com', $user['email']);
    }

    // ------------------------------------------------------------ contact f7

    #[Test]
    public function contact_form_7_records_a_lead_on_the_success_hook(): void
    {
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => 'ada@example.com']);

        $cf7->onMailSent($this->cf7Form());
        $response = $cf7->addResponseData([], ['status' => 'mail_sent']);

        self::assertArrayHasKey('openai_ads', $response);
        self::assertSame('lead_created', $response['openai_ads']['event']);
        self::assertNotEmpty($response['openai_ads']['event_id']);
        self::assertSame(['type' => 'customer_action'], $response['openai_ads']['data']);
    }

    /**
     * The event id is the deduplication key: the server has already sent it, and
     * the browser must use the same one or the conversion counts twice.
     */
    #[Test]
    public function the_event_id_in_the_response_is_the_one_the_server_sent(): void
    {
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => 'ada@example.com']);

        $cf7->onMailSent($this->cf7Form());
        $response = $cf7->addResponseData([], ['status' => 'mail_sent']);

        $sent = $this->lastQueuedEvent();
        self::assertSame($sent['id'], $response['openai_ads']['event_id']);
    }

    #[Test]
    public function contact_form_7_hashes_the_email_before_it_leaves(): void
    {
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => ' Ada@Example.COM ']);

        $cf7->onMailSent($this->cf7Form());

        $sent = $this->lastQueuedEvent();
        self::assertSame(
            ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $sent['user']['emails_sha256'],
        );
    }

    /**
     * A response that is not a successful send must not carry a conversion -
     * this is the difference between measuring a lead and measuring a click.
     */
    #[Test]
    public function no_conversion_is_attached_to_an_unsuccessful_response(): void
    {
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => 'ada@example.com']);

        $cf7->onMailSent($this->cf7Form());
        $response = $cf7->addResponseData([], ['status' => 'validation_failed']);

        self::assertArrayNotHasKey('openai_ads', $response);
    }

    #[Test]
    public function a_second_response_does_not_reuse_the_first_submissions_id(): void
    {
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => 'ada@example.com']);

        $cf7->onMailSent($this->cf7Form());
        $cf7->addResponseData([], ['status' => 'mail_sent']);
        $second = $cf7->addResponseData([], ['status' => 'mail_sent']);

        self::assertArrayNotHasKey('openai_ads', $second);
    }

    #[Test]
    public function contact_form_7_is_only_available_when_it_is_installed(): void
    {
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));

        // The stub bootstrap does not define WPCF7_ContactForm.
        self::assertFalse($cf7->isAvailable());
    }

    // ------------------------------------------------------------- elementor

    #[Test]
    public function elementor_records_a_lead_and_returns_the_id_through_the_handler(): void
    {
        $elementor = new ElementorForms(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $record = new FakeElementorRecord(
            [
                'field_a' => ['id' => 'field_a', 'type' => 'email', 'title' => 'Email', 'value' => 'ada@example.com'],
                'field_b' => ['id' => 'field_b', 'type' => 'tel', 'title' => 'Phone', 'value' => '+40 746 123 456'],
            ],
            ['id' => '7', 'form_name' => 'Quote request'],
        );
        $handler = new FakeElementorHandler();

        $elementor->onNewRecord($record, $handler);

        self::assertArrayHasKey('openai_ads', $handler->responseData);
        self::assertSame('lead_created', $handler->responseData['openai_ads']['event']);

        $sent = $this->lastQueuedEvent();
        self::assertSame($sent['id'], $handler->responseData['openai_ads']['event_id']);
        self::assertArrayHasKey('phone_numbers_sha256', $sent['user']);
    }

    #[Test]
    public function elementor_forms_needs_pro_not_just_elementor(): void
    {
        $elementor = new ElementorForms(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));

        self::assertFalse($elementor->isAvailable());
    }

    // ---------------------------------------------------------------- mapping

    #[Test]
    public function a_form_can_be_mapped_to_different_event_semantics(): void
    {
        \add_filter('openai_ads_form_event', static fn (): string => 'appointment_scheduled');
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => 'ada@example.com']);

        $cf7->onMailSent($this->cf7Form());

        self::assertSame('appointment_scheduled', $this->lastQueuedEvent()['type']);
    }

    #[Test]
    public function a_site_can_supply_identity_the_automatic_extraction_cannot_find(): void
    {
        \add_filter(
            'openai_ads_form_user_data',
            static fn (): array => ['email' => 'mapped@example.com'],
        );
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['mystery-field' => 'mapped@example.com']);

        $cf7->onMailSent($this->cf7Form([new FakeCf7Tag('text', 'mystery-field')]));

        $sent = $this->lastQueuedEvent();
        self::assertArrayHasKey('emails_sha256', $sent['user']);
    }

    #[Test]
    public function a_custom_event_carries_its_name_to_both_sides(): void
    {
        \add_filter('openai_ads_form_event', static fn (): string => 'custom');
        \add_filter('openai_ads_form_custom_event_name', static fn (): string => 'whatsapp_lead');
        $cf7 = new ContactForm7(new LeadRecorder(Plugin::boot(__FILE__, '0.1.0')));
        $this->givenCf7Submission(['your-email' => 'ada@example.com']);

        $cf7->onMailSent($this->cf7Form());
        $response = $cf7->addResponseData([], ['status' => 'mail_sent']);

        self::assertSame('whatsapp_lead', $response['openai_ads']['custom_event_name']);
        self::assertSame('whatsapp_lead', $this->lastQueuedEvent()['custom_event_name']);
    }

    // ---------------------------------------------------------------- registry

    #[Test]
    public function the_registry_knows_both_form_integrations(): void
    {
        $plugin = Plugin::boot(__FILE__, '0.1.0');
        $ids = array_map(
            static fn ($i): string => $i->id(),
            (new Registry($plugin, new Settings()))->all(),
        );

        self::assertContains('contact_form_7', $ids);
        self::assertContains('elementor_forms', $ids);
    }

    /**
     * Neither host plugin is installed here, so nothing registers - which is the
     * point: a site running neither pays for neither.
     */
    #[Test]
    public function nothing_registers_when_no_host_plugin_is_present(): void
    {
        $plugin = Plugin::boot(__FILE__, '0.1.0');
        $registry = new Registry($plugin, new Settings());

        self::assertSame([], $registry->available());
        self::assertFalse($registry->register());
    }

    #[Test]
    public function integrations_are_on_by_default_and_can_be_switched_off(): void
    {
        $settings = new Settings();
        self::assertTrue($settings->integrationEnabled('contact_form_7'));

        WpStubs::$options[Settings::OPTION]['integrations'] = ['contact_form_7' => false];
        $settings->forget();

        self::assertFalse($settings->integrationEnabled('contact_form_7'));
        self::assertTrue($settings->integrationEnabled('elementor_forms'));
    }

    /**
     * An unchecked checkbox is absent from the POST body, so the form states
     * which integrations it rendered. Without that, switching one off would
     * silently fall back to the default and turn it straight back on.
     */
    #[Test]
    public function unchecking_an_integration_is_recorded_rather_than_defaulting_back_on(): void
    {
        $result = (new Settings())->sanitize([
            'integrations_present' => ['contact_form_7', 'elementor_forms'],
            'integrations' => ['elementor_forms' => '1'],
        ]);

        self::assertFalse($result['integrations']['contact_form_7']);
        self::assertTrue($result['integrations']['elementor_forms']);
    }

    #[Test]
    public function a_settings_form_without_the_section_leaves_the_toggles_alone(): void
    {
        WpStubs::$options[Settings::OPTION]['integrations'] = ['contact_form_7' => false];

        $result = (new Settings())->sanitize(['pixel_id' => 'px-1']);

        self::assertFalse($result['integrations']['contact_form_7']);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $posted
     */
    private function givenCf7Submission(array $posted): void
    {
        Cf7SubmissionStub::$posted = $posted;
    }

    /**
     * @param list<FakeCf7Tag>|null $tags
     */
    private function cf7Form(?array $tags = null): FakeCf7Form
    {
        return new FakeCf7Form(12, 'Contact', $tags ?? [
            new FakeCf7Tag('email', 'your-email'),
            new FakeCf7Tag('text', 'your-subject'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastQueuedEvent(): array
    {
        $plugin = Plugin::instance();
        self::assertNotNull($plugin);

        $response = $plugin->measurement()->flush();
        self::assertNotNull($response, 'Nothing was queued.');

        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);

        return $body['events'][0];
    }
}
