<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SchedulerStubs;
use WebaroundLabs\OpenAIAds\WordPress\Delivery\ScheduledDelivery;
use WebaroundLabs\OpenAIAds\WordPress\Measurement;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

#[CoversClass(ScheduledDelivery::class)]
#[CoversClass(Measurement::class)]
#[CoversClass(Plugin::class)]
final class ScheduledDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        SchedulerStubs::reset();
        Plugin::reset();
        WpStubs::$options[Settings::OPTION] = ['pixel_id' => 'px-1', 'capi_key' => 'secret'];
    }

    protected function tearDown(): void
    {
        Plugin::reset();
    }

    #[Test]
    public function a_batch_is_stored_and_queued_instead_of_being_sent_in_the_request(): void
    {
        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());

        self::assertNull($measurement->flush(), 'A deferred batch reports no response.');
        self::assertSame([], WpStubs::$requests, 'Nothing may be sent during the request.');

        self::assertCount(1, SchedulerStubs::$scheduled);
        self::assertSame(ScheduledDelivery::HOOK, SchedulerStubs::$scheduled[0]['hook']);
        self::assertSame('openai-ads', SchedulerStubs::$scheduled[0]['group']);
    }

    #[Test]
    public function the_queued_batch_is_delivered_on_a_later_request(): void
    {
        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());
        $measurement->flush();

        $key = (string) SchedulerStubs::$scheduled[0]['args'][0];

        // A fresh instance, as the later request would have.
        $this->measurement()->deliverScheduledBatch($key);

        self::assertCount(1, WpStubs::$requests);
        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);
        self::assertSame('lead_created', $body['events'][0]['type']);
    }

    /**
     * The batch is claimed - removed from storage - before the send is
     * attempted. If Action Scheduler reclaims a timed-out action and runs it
     * again, there is nothing left to send twice. At-most-once is the safe
     * direction for conversion data.
     */
    #[Test]
    public function a_repeated_action_finds_nothing_left_to_send(): void
    {
        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());
        $measurement->flush();
        $key = (string) SchedulerStubs::$scheduled[0]['args'][0];

        $this->measurement()->deliverScheduledBatch($key);
        $this->measurement()->deliverScheduledBatch($key);

        self::assertCount(1, WpStubs::$requests, 'The batch must be sent exactly once.');
    }

    #[Test]
    public function an_unknown_key_does_nothing(): void
    {
        $this->measurement()->deliverScheduledBatch(ScheduledDelivery::OPTION_PREFIX . 'missing');

        self::assertSame([], WpStubs::$requests);
    }

    /** A key that is not one of ours must not be able to read arbitrary options. */
    #[Test]
    public function a_key_outside_the_plugins_prefix_is_refused(): void
    {
        WpStubs::$options['some_other_plugin_option'] = ['events' => []];

        self::assertNull((new ScheduledDelivery())->claim('some_other_plugin_option'));
        self::assertArrayHasKey('some_other_plugin_option', WpStubs::$options);
    }

    /**
     * On a site with DISABLE_WP_CRON and no real cron, queued actions can sit
     * unprocessed. The API refuses events older than seven days and fails a
     * batch as a whole, so a stale batch would take every event with it.
     */
    #[Test]
    public function a_batch_that_sat_too_long_is_dropped_rather_than_sent(): void
    {
        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());
        $measurement->flush();
        $key = (string) SchedulerStubs::$scheduled[0]['args'][0];

        WpStubs::$options[$key]['created'] = time() - (7 * 86400);

        $this->measurement()->deliverScheduledBatch($key);

        self::assertSame([], WpStubs::$requests);
    }

    /**
     * A plugin update that renames or removes a class leaves
     * __PHP_Incomplete_Class in the stored batch. Dropping those is the only
     * safe response - they cannot be sent, and failing the action helps nobody.
     */
    #[Test]
    public function events_that_no_longer_unserialize_are_dropped_safely(): void
    {
        $key = ScheduledDelivery::OPTION_PREFIX . 'stale';
        WpStubs::$options[$key] = [
            'events' => ['not-an-event', 42],
            'validate_only' => false,
            'created' => time(),
        ];

        $this->measurement()->deliverScheduledBatch($key);

        self::assertSame([], WpStubs::$requests);
        self::assertArrayNotHasKey($key, WpStubs::$options, 'The unusable batch is cleaned up.');
    }

    #[Test]
    public function the_validation_flag_survives_the_handover(): void
    {
        WpStubs::$options[Settings::OPTION]['validate_only'] = true;
        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());
        $measurement->flush();
        $key = (string) SchedulerStubs::$scheduled[0]['args'][0];

        $this->measurement()->deliverScheduledBatch($key);

        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);
        self::assertTrue($body['validate_only']);
    }

    /**
     * The setting exists so a site can force the old behaviour, and so a site
     * with a broken cron is not stuck with events that never leave.
     */
    #[Test]
    public function the_scheduler_can_be_switched_off_in_favour_of_sending_inline(): void
    {
        WpStubs::$options[Settings::OPTION]['use_scheduler'] = false;
        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());

        self::assertNotNull($measurement->flush());
        self::assertCount(1, WpStubs::$requests);
        self::assertSame([], SchedulerStubs::$scheduled);
    }

    /**
     * The behaviour on a site with no WooCommerce and therefore no Action
     * Scheduler: unchanged from before this feature existed.
     */
    #[Test]
    public function a_site_without_action_scheduler_still_sends_in_the_request(): void
    {
        $unavailable = new class () extends ScheduledDelivery {
            public function isAvailable(): bool
            {
                return false;
            }
        };

        $measurement = new Measurement(new Settings(), scheduler: $unavailable);
        $measurement->record(EventFactory::lead());

        self::assertNotNull($measurement->flush());
        self::assertCount(1, WpStubs::$requests);
        self::assertSame([], SchedulerStubs::$scheduled);
    }

    /**
     * The half of the handover that lives in WordPress rather than in this
     * class, and the half that is easy to leave out: queueing an action works
     * perfectly well when nothing is listening for it, and the conversion
     * disappears without an error. Every WooCommerce site takes this path by
     * default.
     */
    #[Test]
    public function booting_the_plugin_registers_the_callback_action_scheduler_will_run(): void
    {
        Plugin::boot(__DIR__ . '/../conversion-tracking-for-openai-ads.php', '0.1.0');

        $listeners = WpStubs::$filters[ScheduledDelivery::HOOK] ?? [];
        self::assertCount(1, $listeners, 'Nothing would ever deliver a queued batch.');

        $measurement = $this->measurement();
        $measurement->record(EventFactory::lead());
        $measurement->flush();

        $listeners[0]((string) SchedulerStubs::$scheduled[0]['args'][0]);

        self::assertCount(1, WpStubs::$requests, 'The queued batch was not delivered.');
        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);
        self::assertSame('lead_created', $body['events'][0]['type']);
    }

    private function measurement(): Measurement
    {
        return new Measurement(new Settings());
    }
}
