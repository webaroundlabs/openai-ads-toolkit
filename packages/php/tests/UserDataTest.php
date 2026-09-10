<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\UserData;

#[CoversClass(UserData::class)]
final class UserDataTest extends TestCase
{
    /**
     * Every normalization case pinned in the shared specification, driven
     * straight from the fixture so PHP and the future TypeScript core cannot
     * disagree about what a value hashes to.
     *
     * @param array{field: string, raw: string, normalized: string, sha256: string} $case
     */
    #[Test]
    #[DataProvider('pinnedNormalizationCases')]
    public function it_reproduces_every_pinned_digest(array $case): void
    {
        $user = self::userWith($case['field'], $case['raw']);
        $capiKey = self::capiKeyFor($case['field']);

        self::assertSame([$case['sha256']], $user->toCapiArray()[$capiKey]);
    }

    /**
     * @return iterable<string, array{array{field: string, raw: string, normalized: string, sha256: string}}>
     */
    public static function pinnedNormalizationCases(): iterable
    {
        $fixture = Spec::load('fixtures/normalization.cases.json');

        foreach ($fixture['valid'] as $i => $case) {
            yield sprintf('%s #%d: %s', $case['field'], $i, $case['asserts']) => [$case];
        }
    }

    /**
     * The bug this whole fixture set exists to prevent: a byte-wise lowercase
     * leaves a diacritic untouched and produces a digest that matches nobody.
     */
    #[Test]
    public function unicode_lowercasing_produces_the_correct_digest_not_the_byte_wise_one(): void
    {
        $guard = Spec::load('fixtures/normalization.cases.json')['divergence_guard'];

        $actual = UserData::create(lastName: $guard['raw'])->toCapiArray()['last_names_sha256'][0];

        self::assertSame($guard['correct']['sha256'], $actual);
        self::assertNotSame(
            $guard['wrong']['sha256'],
            $actual,
            'Name normalization fell back to a byte-wise lowercase; use mb_strtolower.',
        );
    }

    #[Test]
    public function a_fully_populated_user_matches_the_conversions_api_fixture(): void
    {
        $fixture = Spec::load('fixtures/user.full.capi.json');
        $in = $fixture['input'];

        $user = UserData::create(
            email: $in['email'],
            phone: $in['phone'],
            externalId: $in['external_id'],
            firstName: $in['first_name'],
            lastName: $in['last_name'],
            country: $in['country'],
            city: $in['city'],
            region: $in['region'],
            postalCode: $in['postal_code'],
            obref: $in['obref'],
            ipAddress: $in['ip_address'],
            userAgent: $in['user_agent'],
            androidAdvertisingId: $in['android_advertising_id'],
        );

        self::assertSame($fixture['expected'], $user->toCapiArray());
    }

    #[Test]
    public function hashed_fields_are_lists_and_opaque_fields_are_scalars(): void
    {
        $payload = UserData::create(
            email: 'ada@example.com',
            obref: '123e4567-e89b-42d3-a456-426614174000',
            ipAddress: '203.0.113.1',
        )->toCapiArray();

        self::assertIsList($payload['emails_sha256']);
        self::assertIsString($payload['obref']);
        self::assertIsString($payload['ip_address']);
    }

    #[Test]
    public function normalization_is_idempotent(): void
    {
        $once = UserData::create(email: ' Ada@Example.COM ')->toCapiArray();
        $twice = UserData::create(email: 'ada@example.com')->toCapiArray();

        self::assertSame($once, $twice);
    }

    #[Test]
    public function an_external_id_keeps_its_case(): void
    {
        $upper = UserData::create(externalId: 'CUST-42')->toCapiArray();
        $lower = UserData::create(externalId: 'cust-42')->toCapiArray();

        self::assertNotSame($lower['external_ids_sha256'], $upper['external_ids_sha256']);
    }

    #[Test]
    public function an_obref_is_never_normalized(): void
    {
        $raw = '  Mixed-Case_Value==  ';

        self::assertSame($raw, UserData::create(obref: $raw)->toCapiArray()['obref']);
    }

    /**
     * @param array{field: string, raw: string, reason: string} $case
     */
    #[Test]
    #[DataProvider('pinnedRejections')]
    public function it_rejects_the_pinned_invalid_values(array $case): void
    {
        $this->expectException(InvalidArgument::class);

        self::userWith($case['field'], $case['raw']);
    }

    /**
     * @return iterable<string, array{array{field: string, raw: string, reason: string}}>
     */
    public static function pinnedRejections(): iterable
    {
        foreach (Spec::load('fixtures/normalization.cases.json')['rejected'] as $case) {
            yield $case['reason'] => [$case];
        }
    }

    /**
     * Raw identifiers must not travel in exception messages, because those
     * reach logs and error reporters.
     */
    #[Test]
    public function a_rejection_message_never_contains_the_raw_identifier(): void
    {
        try {
            UserData::create(phone: '+40 751 111 222 333 444');
            self::fail('Expected the phone to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringNotContainsString('751', $e->getMessage());
            self::assertStringNotContainsString('40751111222333444', $e->getMessage());
            self::assertStringContainsString('between 8 and 15 digits', $e->getMessage());
        }
    }

    #[Test]
    public function an_identity_value_that_normalizes_to_nothing_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('normalized to an empty value');

        UserData::create(email: '   ');
    }

    /**
     * Geographic values are hints rather than identifiers, so a blank one is
     * treated as absent instead of throwing - a half-filled checkout address
     * must not break a conversion.
     */
    #[Test]
    public function a_blank_geographic_value_is_treated_as_absent(): void
    {
        $payload = UserData::create(city: '   ', country: 'RO')->toCapiArray();

        self::assertArrayNotHasKey('cities', $payload);
        self::assertSame(['RO'], $payload['countries']);
    }

    #[Test]
    public function an_invalid_ip_address_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('valid IPv4 or IPv6');

        UserData::create(ipAddress: '999.1.1.1');
    }

    #[Test]
    public function an_ipv6_address_is_accepted(): void
    {
        $payload = UserData::create(ipAddress: '2001:db8::1')->toCapiArray();

        self::assertSame('2001:db8::1', $payload['ip_address']);
    }

    #[Test]
    public function an_advertising_id_that_is_not_a_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('must be a UUID');

        UserData::create(androidAdvertisingId: 'not-a-uuid');
    }

    #[Test]
    public function an_untouched_user_is_empty_and_serializes_to_nothing(): void
    {
        $user = UserData::create();

        self::assertTrue($user->isEmpty());
        self::assertSame([], $user->toCapiArray());
    }

    private static function userWith(string $field, string $raw): UserData
    {
        return match ($field) {
            'email' => UserData::create(email: $raw),
            'phone' => UserData::create(phone: $raw),
            'external_id' => UserData::create(externalId: $raw),
            'first_name' => UserData::create(firstName: $raw),
            'last_name' => UserData::create(lastName: $raw),
            default => throw new \LogicException(sprintf('Fixture uses unmapped field "%s".', $field)),
        };
    }

    private static function capiKeyFor(string $field): string
    {
        $spec = Spec::load('user.json');

        return $spec['fields'][$field]['capi']['key'];
    }
}
