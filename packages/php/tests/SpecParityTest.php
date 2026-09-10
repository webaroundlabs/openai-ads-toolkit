<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\DataShape;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\UserData;

/**
 * Keeps this runtime honest against `packages/spec`.
 *
 * The specification deliberately exists in three places - the JSON, this PHP
 * package, and the TypeScript package to come. That duplication is safe only
 * because of these assertions: when OpenAI adds an event, the spec is edited
 * once and every runtime that has not caught up goes red on the same commit.
 * The alternative, generating code from the JSON, would buy nothing here except
 * a build step and generated stack traces.
 */
#[CoversClass(EventName::class)]
#[CoversClass(ActionSource::class)]
#[CoversClass(Client::class)]
final class SpecParityTest extends TestCase
{
    #[Test]
    public function the_event_catalogue_matches_the_specification(): void
    {
        $spec = array_keys(Spec::load('events.json')['events']);
        $php = array_map(static fn (EventName $e): string => $e->value, EventName::cases());

        sort($spec);
        sort($php);

        self::assertSame($spec, $php, 'EventName has drifted from events.json.');
    }

    #[Test]
    public function the_action_sources_match_the_specification(): void
    {
        $spec = Spec::load('events.json')['action_sources'];
        $php = array_map(static fn (ActionSource $a): string => $a->value, ActionSource::cases());

        sort($spec);
        sort($php);

        self::assertSame($spec, $php);
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[Test]
    #[DataProvider('specifiedEvents')]
    public function each_event_agrees_with_the_specification(string $name, array $definition): void
    {
        $event = EventName::from($name);

        self::assertSame(
            $definition['data_shape'],
            $event->dataShape()->value,
            sprintf('Data shape for "%s" disagrees with the spec.', $name),
        );

        self::assertSame(
            $definition['pixel'],
            $event->supportsPixel(),
            sprintf('Pixel support for "%s" disagrees with the spec.', $name),
        );

        self::assertSame(
            $definition['capi'],
            $event->supportsCapi(),
            sprintf('Conversions API support for "%s" disagrees with the spec.', $name),
        );

        $allowed = $event->allowedActionSources();
        $allowedValues = $allowed === null
            ? null
            : array_map(static fn (ActionSource $a): string => $a->value, $allowed);

        self::assertSame(
            $definition['action_sources'] ?? null,
            $allowedValues,
            sprintf('Permitted action sources for "%s" disagree with the spec.', $name),
        );

        self::assertSame(
            $definition['requires_custom_event_name'] ?? false,
            $event->requiresCustomEventName(),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function specifiedEvents(): iterable
    {
        foreach (Spec::load('events.json')['events'] as $name => $definition) {
            yield $name => [$name, $definition];
        }
    }

    #[Test]
    public function the_data_shapes_match_the_specification(): void
    {
        $spec = array_keys(Spec::load('events.json')['data_shapes']);
        $php = array_map(
            static fn (EventName $e): string => $e->dataShape()->value,
            EventName::cases(),
        );

        sort($spec);
        $php = array_values(array_unique($php));
        sort($php);

        self::assertSame($spec, $php, 'Every documented data shape should be reachable from some event.');
    }

    /**
     * Parity assertion 3: a fully-populated user must emit exactly the key set
     * the specification declares, with the documented cardinality.
     */
    #[Test]
    public function the_identity_serialization_matches_the_specification(): void
    {
        $spec = Spec::load('user.json');
        $payload = self::fullyPopulatedUser()->toCapiArray();

        $expectedKeys = array_map(
            static fn (array $field): string => $field['capi']['key'],
            $spec['fields'],
        );

        self::assertSame(array_values($expectedKeys), array_keys($payload));

        foreach ($spec['fields'] as $logical => $field) {
            $key = $field['capi']['key'];
            $shouldBeList = $field['capi']['cardinality'] === 'list';

            self::assertSame(
                $shouldBeList,
                is_array($payload[$key]),
                sprintf('Cardinality of "%s" (%s) disagrees with the spec.', $logical, $key),
            );
        }
    }

    /**
     * The Pixel takes singular keys and omits everything the browser supplies
     * itself. No Pixel serializer exists yet, so this asserts the fixture pair
     * stays coherent - it becomes an assertion about code in the JS phase.
     */
    #[Test]
    public function the_pixel_fixture_carries_the_same_digests_under_singular_keys(): void
    {
        $spec = Spec::load('user.json');
        $capi = Spec::load('fixtures/user.full.capi.json')['expected'];
        $pixel = Spec::load('fixtures/user.full.pixel.json')['expected'];

        foreach ($spec['fields'] as $logical => $field) {
            if ($field['pixel'] === null) {
                self::assertArrayNotHasKey(
                    $field['capi']['key'],
                    $pixel,
                    sprintf('"%s" is marked pixel:null and must not be sent to the Pixel.', $logical),
                );

                continue;
            }

            $capiValue = $capi[$field['capi']['key']];

            self::assertSame(
                is_array($capiValue) ? $capiValue[0] : $capiValue,
                $pixel[$field['pixel']['key']],
                sprintf('Digest for "%s" differs between the two serializations.', $logical),
            );
        }
    }

    #[Test]
    public function the_custom_event_name_pattern_is_the_one_in_the_specification(): void
    {
        self::assertSame(
            Spec::load('events.json')['patterns']['custom_event_name'],
            Event::CUSTOM_EVENT_NAME_PATTERN,
        );
    }

    #[Test]
    public function the_client_constants_match_the_specification(): void
    {
        $spec = Spec::load('events.json');

        self::assertSame($spec['envelope']['endpoint']['url'], Client::ENDPOINT);
        self::assertSame($spec['limits']['batch_max_events'], Client::MAX_BATCH_SIZE);
        self::assertSame($spec['limits']['timestamp_max_age_ms'], Client::MAX_AGE_MS);
        self::assertSame($spec['limits']['timestamp_max_future_ms'], Client::MAX_FUTURE_MS);
        self::assertSame($spec['patterns']['integration_source'], Client::INTEGRATION_SOURCE_PATTERN);
    }

    #[Test]
    public function the_default_integration_source_satisfies_the_specified_pattern(): void
    {
        $pattern = Spec::load('events.json')['patterns']['integration_source'];

        self::assertMatchesRegularExpression(
            '/' . $pattern . '/',
            Client::DEFAULT_INTEGRATION_SOURCE,
        );
    }

    /**
     * Which shapes carry a contents array and a plan id is spec knowledge that
     * now lives in PHP as well, so it needs the same parity guarantee as the
     * event catalogue.
     */
    #[Test]
    public function each_data_shape_carries_what_the_specification_says_it_does(): void
    {
        $shapes = Spec::load('events.json')['data_shapes'];

        foreach ($shapes as $name => $fields) {
            $shape = DataShape::from($name);

            self::assertSame(
                array_key_exists('contents', $fields),
                $shape->acceptsContents(),
                sprintf('Contents support for "%s" disagrees with the spec.', $name),
            );

            self::assertSame(
                array_key_exists('plan_id', $fields),
                $shape->acceptsPlanId(),
                sprintf('Plan id support for "%s" disagrees with the spec.', $name),
            );
        }
    }

    /**
     * The two Conversions API only fields must be marked as such in the spec,
     * because the Pixel serializer strips exactly what carries that marker.
     */
    #[Test]
    public function the_capi_only_content_fields_are_the_ones_php_can_emit(): void
    {
        $content = Spec::load('events.json')['objects']['content'];

        $capiOnly = array_keys(array_filter(
            $content,
            static fn (array $field): bool => ($field['capi_only'] ?? false) === true,
        ));

        sort($capiOnly);

        self::assertSame(['group_id', 'variant_dict'], $capiOnly);
    }

    private static function fullyPopulatedUser(): UserData
    {
        return UserData::create(
            email: 'ada@example.com',
            phone: '+40 746 123 456',
            externalId: 'CUST-00042',
            firstName: 'Ion',
            lastName: 'Ștefănescu',
            country: 'RO',
            city: 'București',
            region: 'București',
            postalCode: '010101',
            obref: '123e4567-e89b-42d3-a456-426614174000',
            ipAddress: '203.0.113.1',
            userAgent: 'Mozilla/5.0',
            androidAdvertisingId: '38400000-8cf0-11bd-b23e-10b96e40000d',
        );
    }
}
