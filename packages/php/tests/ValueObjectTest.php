<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Money;
use WebaroundLabs\OpenAIAds\SystemClock;

#[CoversClass(Money::class)]
#[CoversClass(EventId::class)]
#[CoversClass(SystemClock::class)]
final class ValueObjectTest extends TestCase
{
    #[Test]
    public function money_keeps_minor_units_verbatim_and_uppercases_the_currency(): void
    {
        $money = Money::minor(1299, 'eur');

        self::assertSame(1299, $money->minorUnits);
        self::assertSame('EUR', $money->currency);
    }

    #[Test]
    #[DataProvider('invalidCurrencies')]
    public function money_rejects_anything_that_is_not_an_alpha_3_code(string $currency): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('ISO 4217 alpha-3');

        Money::minor(1299, $currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCurrencies(): iterable
    {
        yield 'too short' => ['EU'];
        yield 'too long' => ['EURO'];
        yield 'numeric' => ['978'];
        yield 'empty' => [''];
        yield 'symbol' => ['€'];
    }

    /**
     * Major units are the most common mistake made against any conversions API.
     * Under strict_types the type system rejects them at the call site, which is
     * the whole reason this value object exists.
     */
    #[Test]
    public function money_rejects_a_major_unit_decimal_at_the_type_level(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line intentional: proving strict_types rejects a float */
        Money::minor(12.99, 'EUR');
    }

    #[Test]
    public function money_permits_large_amounts_for_zero_decimal_currencies(): void
    {
        // Beyond 2^31: a reminder that a 32-bit PHP build is unsupported.
        $money = Money::minor(3_000_000_000, 'JPY');

        self::assertSame(3_000_000_000, $money->minorUnits);
    }

    #[Test]
    public function money_permits_a_negative_amount_because_no_rule_forbids_it(): void
    {
        self::assertTrue(Money::minor(-500, 'EUR')->isNegative());
    }

    #[Test]
    public function an_event_id_is_trimmed_and_stringable(): void
    {
        $id = EventId::fromBusinessId('  order_123  ');

        self::assertSame('order_123', $id->value);
        self::assertSame('order_123', (string) $id);
        self::assertTrue($id->equals(EventId::fromBusinessId('order_123')));
    }

    #[Test]
    #[DataProvider('emptyIdentifiers')]
    public function an_empty_event_id_is_rejected(string $id): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('non-empty');

        EventId::fromBusinessId($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tab' => ["\t"];
    }

    #[Test]
    public function the_system_clock_returns_a_plausible_millisecond_timestamp(): void
    {
        $now = (new SystemClock())->nowMs();

        // Milliseconds, not seconds: 2001-09-09 in ms is the lower bound here.
        self::assertGreaterThan(1_000_000_000_000, $now);
        self::assertLessThan(time() * 1000 + 5000, $now);
    }
}
