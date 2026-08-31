<?php

declare(strict_types=1);

namespace Cel\Tests\Runtime\Extension;

use Cel\Exception\EvaluationException;
use Cel\Extension\Core\CoreExtension;
use Cel\Extension\DateTime\DateTimeExtension;
use Cel\Extension\List\ListExtension;
use Cel\Extension\Math\MathExtension;
use Cel\Extension\String\StringExtension;
use Cel\Runtime\Configuration;
use Cel\Tests\Runtime\RuntimeTestCase;
use Cel\Value\BooleanValue;
use Cel\Value\StringValue;
use Cel\Value\Value;
use Override;

/**
 * The `date()` format contract.
 *
 * Every case here is also asserted, character for character, by the other
 * implementations of this language, so an expression authored once renders the
 * same text wherever it runs. Changing an expectation here without changing it
 * there breaks that.
 */
final class DateFunctionTest extends RuntimeTestCase
{
    /**
     * @return iterable<string, array{
     *     0: string,
     *     1: array<string, mixed>,
     *     2: Value|EvaluationException,
     *     3?: null|Configuration
     * }>
     */
    #[Override]
    public static function provideEvaluationCases(): iterable
    {
        yield 'the library default format is ISO 8601' => [
            'date("2026-06-11")',
            [],
            new StringValue('2026-06-11T00:00:00'),
        ];

        yield 'an empty format falls back to the default' => [
            'date("2026-06-11", "")',
            [],
            new StringValue('2026-06-11T00:00:00'),
        ];

        yield 'a host default format replaces it' => [
            'date("2026-06-11")',
            [],
            new StringValue('Jun 11, 2026'),
            self::configuredWith('UTC', 'M j, Y'),
        ];

        yield 'a call still names its own format over the host default' => [
            'date("2026-06-11", "Y")',
            [],
            new StringValue('2026'),
            self::configuredWith('UTC', 'M j, Y'),
        ];

        yield 'a host timezone moves the rendered day' => [
            'date("2026-06-11T23:30:00Z", "Y-m-d H:i")',
            [],
            new StringValue('2026-06-12 09:30'),
            self::configuredWith('Australia/Sydney', 'M j, Y'),
        ];

        // --- Year, month, day ---

        yield 'numeric year, month and day' => [
            'date("2026-06-11", "Y-m-d")',
            [],
            new StringValue('2026-06-11'),
        ];

        yield 'short year, unpadded month and day' => [
            'date("2026-06-11", "y/n/j")',
            [],
            new StringValue('26/6/11'),
        ];

        yield 'month and weekday names' => [
            'date("2026-06-11", "l, F jS")',
            [],
            new StringValue('Thursday, June 11th'),
        ];

        yield 'abbreviated month and weekday names' => [
            'date("2026-06-11", "D M")',
            [],
            new StringValue('Thu Jun'),
        ];

        yield 'weekday numbers, day of year, days in month, leap year' => [
            'date("2026-06-11", "N w z t L")',
            [],
            new StringValue('4 4 161 30 0'),
        ];

        // --- Time ---

        yield 'twenty-four hour time' => [
            'date("2026-06-11 15:04:05", "H:i:s")',
            [],
            new StringValue('15:04:05'),
        ];

        yield 'twelve hour time with meridiem' => [
            'date("2026-06-11 15:04:05", "g:i a")',
            [],
            new StringValue('3:04 pm'),
        ];

        yield 'unpadded and padded hours' => [
            'date("2026-06-11 05:04:05", "G h A")',
            [],
            new StringValue('5 05 AM'),
        ];

        yield 'the unix second' => [
            'date("1970-01-01 00:00:42", "U")',
            [],
            new StringValue('42'),
        ];

        // --- Characters outside the set ---

        yield 'a timezone character renders as itself' => [
            'date("2026-06-11", "Y T")',
            [],
            new StringValue('2026 T'),
        ];

        yield 'an offset character renders as itself' => [
            'date("2026-06-11", "Y P O")',
            [],
            new StringValue('2026 P O'),
        ];

        yield 'an ISO week character renders as itself' => [
            'date("2026-06-11", "W o")',
            [],
            new StringValue('W o'),
        ];

        yield 'a whole-datetime character renders as itself' => [
            'date("2026-06-11", "c r")',
            [],
            new StringValue('c r'),
        ];

        yield 'punctuation and words pass through' => [
            'date("2026-06-11", "on M j")',
            // `o` and `n` are the ISO year and the unpadded month, so the word
            // "on" renders as its parts. This is why a literal needs escaping.
            [],
            new StringValue('o6 Jun 11'),
        ];

        // --- Escaping ---

        yield 'a backslash renders the next character literally' => [
            'date("2026-06-11", "\\\\Y Y")',
            [],
            new StringValue('Y 2026'),
        ];

        yield 'a trailing backslash renders nothing' => [
            'date("2026-06-11", "Y\\\\")',
            [],
            new StringValue('2026'),
        ];

        // --- The value ---

        yield 'a unix second count as an integer' => [
            'date(0, "Y-m-d")',
            [],
            new StringValue('1970-01-01'),
        ];

        yield 'a unix second count as a float' => [
            'date(86400.9, "Y-m-d")',
            [],
            new StringValue('1970-01-02'),
        ];

        yield 'a fractional unix second keeps its fraction' => [
            'date(86400.9, "H:i:s.v")',
            [],
            new StringValue('00:00:00.900'),
        ];

        yield 'a timestamp value' => [
            'date(timestamp("2026-06-11T15:04:05Z"), "Y-m-d H:i")',
            [],
            new StringValue('2026-06-11 15:04'),
        ];

        yield 'a variable carrying a date string' => [
            'date(order.placedAt, "Y-m-d")',
            ['order' => ['placedAt' => '2026-06-11 15:04:05']],
            new StringValue('2026-06-11'),
        ];

        yield 'null reads the current moment' => [
            'size(date(null, "Y")) == 4',
            [],
            new BooleanValue(true),
        ];

        yield 'an empty string reads the current moment' => [
            'size(date("", "Y")) == 4',
            [],
            new BooleanValue(true),
        ];

        yield 'no argument at all reads the current moment' => [
            'size(date()) > 0',
            [],
            new BooleanValue(true),
        ];
    }

    /**
     * A runtime whose host named its own timezone and display format, which is
     * how an application with a display convention wires this up.
     *
     * @param non-empty-string $timezone
     * @param non-empty-string $defaultFormat
     */
    private static function configuredWith(string $timezone, string $defaultFormat): Configuration
    {
        $configuration = new Configuration(enableStandardExtensions: false);

        $configuration->addExtension(new CoreExtension());
        $configuration->addExtension(new DateTimeExtension($timezone, $defaultFormat));
        $configuration->addExtension(new StringExtension());
        $configuration->addExtension(new ListExtension());
        $configuration->addExtension(new MathExtension());

        return $configuration;
    }
}
