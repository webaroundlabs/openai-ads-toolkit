<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Content;
use WebaroundLabs\OpenAIAds\DataShape;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Money;

#[CoversClass(Content::class)]
#[CoversClass(DataShape::class)]
final class ContentTest extends TestCase
{
    #[Test]
    public function a_line_item_serializes_in_the_documented_order(): void
    {
        $content = Content::create(
            id: 'sku_123',
            groupId: 'product_9',
            name: 'Starter bundle',
            contentType: 'product',
            quantity: 2,
            value: Money::minor(1299, 'EUR'),
            variantDict: ['size' => 'M'],
        );

        self::assertSame([
            'id' => 'sku_123',
            'group_id' => 'product_9',
            'name' => 'Starter bundle',
            'content_type' => 'product',
            'quantity' => 2,
            'amount' => 1299,
            'currency' => 'EUR',
            'variant_dict' => ['size' => 'M'],
        ], $content->toCapiArray());
    }

    #[Test]
    public function absent_fields_are_omitted(): void
    {
        self::assertSame(
            ['id' => 'sku_1', 'quantity' => 1],
            Content::create(id: 'sku_1', quantity: 1)->toCapiArray(),
        );
    }

    #[Test]
    public function an_item_carrying_nothing_at_all_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('at least one field');

        Content::create();
    }

    #[Test]
    public function a_negative_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('must not be negative');

        Content::create(id: 'sku_1', quantity: -1);
    }

    #[Test]
    public function a_variant_dict_must_map_strings_to_strings(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('map of strings to strings');

        /** @phpstan-ignore-next-line intentional: proving the boundary check */
        Content::create(id: 'sku_1', variantDict: ['size' => 42]);
    }

    // --------------------------------------------------------- on the event

    #[Test]
    public function an_order_carries_its_line_items(): void
    {
        $event = $this->order(
            value: Money::minor(2599, 'EUR'),
            contents: [
                Content::create(id: 'sku_1', name: 'Mug', quantity: 1, value: Money::minor(1299, 'EUR')),
                Content::create(id: 'sku_2', name: 'Tea', quantity: 2, value: Money::minor(650, 'EUR')),
            ],
        );

        self::assertSame([
            'type' => 'contents',
            'amount' => 2599,
            'currency' => 'EUR',
            'contents' => [
                ['id' => 'sku_1', 'name' => 'Mug', 'quantity' => 1, 'amount' => 1299, 'currency' => 'EUR'],
                ['id' => 'sku_2', 'name' => 'Tea', 'quantity' => 2, 'amount' => 650, 'currency' => 'EUR'],
            ],
        ], $event->toCapiArray()['data']);
    }

    /**
     * The rule that would otherwise fail an entire 1,000-event batch at the API
     * for a reason the response cannot explain.
     */
    #[Test]
    public function a_customer_action_event_refuses_line_items(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('carries no contents array');

        Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/',
            contents: [Content::create(id: 'sku_1')],
        );
    }

    #[Test]
    public function a_customer_action_event_refuses_a_plan_id(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('carries no plan id');

        Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/',
            planId: 'pro-monthly',
        );
    }

    #[Test]
    public function a_subscription_carries_its_plan_id_before_the_amount(): void
    {
        $event = Event::create(
            name: EventName::SubscriptionCreated,
            id: EventId::fromBusinessId('sub_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/welcome',
            value: Money::minor(1900, 'EUR'),
            planId: 'pro-monthly',
        );

        self::assertSame([
            'type' => 'plan_enrollment',
            'plan_id' => 'pro-monthly',
            'amount' => 1900,
            'currency' => 'EUR',
        ], $event->toCapiArray()['data']);
    }

    #[Test]
    public function contents_must_actually_be_content_objects(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('contents must contain only');

        /** @phpstan-ignore-next-line intentional: proving the boundary check */
        $this->order(contents: [['id' => 'sku_1']]);
    }

    #[Test]
    public function an_empty_contents_array_is_omitted_rather_than_sent(): void
    {
        self::assertSame(['type' => 'contents'], $this->order()->toCapiArray()['data']);
    }

    /**
     * @param array<int, mixed> $contents
     */
    #[Test]
    #[DataProvider('shapesAndWhatTheyAccept')]
    public function each_shape_declares_what_it_carries(
        DataShape $shape,
        bool $contents,
        bool $planId,
    ): void {
        self::assertSame($contents, $shape->acceptsContents());
        self::assertSame($planId, $shape->acceptsPlanId());
    }

    /**
     * @return iterable<string, array{DataShape, bool, bool}>
     */
    public static function shapesAndWhatTheyAccept(): iterable
    {
        yield 'contents' => [DataShape::Contents, true, false];
        yield 'customer_action' => [DataShape::CustomerAction, false, false];
        yield 'plan_enrollment' => [DataShape::PlanEnrollment, true, true];
        yield 'custom' => [DataShape::Custom, true, true];
    }

    /**
     * @param array<int, mixed> $contents
     */
    private function order(?Money $value = null, array $contents = []): Event
    {
        return Event::create(
            name: EventName::OrderCreated,
            id: EventId::fromBusinessId('order_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/thank-you',
            value: $value,
            contents: $contents,
        );
    }
}
