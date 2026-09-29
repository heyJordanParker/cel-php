<?php

declare(strict_types=1);

namespace Cel\Tests\Runtime\Extension;

use Cel\Exception\EvaluationException;
use Cel\Extension\Callable\CallableExtension;
use Cel\Runtime\Configuration;
use Cel\Tests\Runtime\RuntimeTestCase;
use Cel\Value\FloatValue;
use Cel\Value\IntegerValue;
use Cel\Value\StringValue;
use Cel\Value\Value;
use DateTimeImmutable;
use Override;

use function count;
use function implode;
use function is_array;
use function is_string;

final class CallableExtensionTest extends RuntimeTestCase
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
        yield 'a callable with no arguments' => [
            'stamp()',
            [],
            new StringValue('stamped'),
            self::configuration(),
        ];

        yield 'a callable with one string argument' => [
            'greet("world")',
            [],
            new StringValue('hello world'),
            self::configuration(),
        ];

        yield 'a callable reached with an argument kind nobody declared' => [
            'describe(null)',
            [],
            new StringValue('nothing'),
            self::configuration(),
        ];

        yield 'a callable reached with a list argument' => [
            'describe([1, 2])',
            [],
            new StringValue('a list of 2'),
            self::configuration(),
        ];

        // Three arguments, which no fixed enumeration of two-argument
        // signatures would have resolved.
        yield 'a callable with three arguments' => [
            'label("a", 1, 2.5)',
            [],
            new StringValue('a|1|2.5'),
            self::configuration(),
        ];

        yield 'a callable returning a number' => [
            'half(5)',
            [],
            new FloatValue(2.5),
            self::configuration(),
        ];

        yield 'a callable result feeding an operator' => [
            'half(5) + 1',
            [],
            new FloatValue(3.5),
            self::configuration(),
        ];

        yield 'a declared overload still wins over a callable of the same name' => [
            'size("abcd")',
            [],
            new IntegerValue(4),
            self::configuration(),
        ];

        yield 'the callable serves the shapes the declared overloads miss' => [
            'size(true)',
            [],
            new StringValue('unsized'),
            self::configuration(),
        ];

        yield 'a timestamp reaches a callable as a date in UTC' => [
            'instant(timestamp("2026-09-30T03:30:00Z"))',
            [],
            new StringValue('2026-09-30T03:30:00.000000+00:00'),
            self::configuration(),
        ];

        yield 'a timestamp keeps its fraction of a second' => [
            'instant(timestamp("2026-09-30T03:30:00.25Z"))',
            [],
            new StringValue('2026-09-30T03:30:00.250000+00:00'),
            self::configuration(),
        ];

        yield 'a timestamp before 1970 keeps its fraction of a second' => [
            'instant(timestamp("1969-12-31T23:59:58.5Z"))',
            [],
            new StringValue('1969-12-31T23:59:58.500000+00:00'),
            self::configuration(),
        ];

        yield 'a timestamp inside a list reaches a callable as a date' => [
            'instants([timestamp("2026-09-30T03:30:00Z")])',
            [],
            new StringValue('2026-09-30T03:30:00.000000+00:00'),
            self::configuration(),
        ];

        yield 'a timestamp inside a map reaches a callable as a date' => [
            'instants({"paid": timestamp("2026-09-30T03:30:00Z")})',
            [],
            new StringValue('paid=2026-09-30T03:30:00.000000+00:00'),
            self::configuration(),
        ];

        yield 'a timestamp inside an optional reaches a callable as a date' => [
            'instant(optional.of(timestamp("2026-09-30T03:30:00Z")))',
            [],
            new StringValue('2026-09-30T03:30:00.000000+00:00'),
            self::configuration(),
        ];
    }

    private static function configuration(): Configuration
    {
        $configuration = new Configuration();
        $configuration->addExtension(new CallableExtension([
            'stamp' => static fn(): string => 'stamped',
            'greet' => static fn(mixed $name): string => 'hello ' . (string) $name,
            'describe' => static fn(mixed $value): string => match (true) {
                null === $value => 'nothing',
                is_array($value) => 'a list of ' . count($value),
                default => 'something',
            },
            'label' => static fn(string|int|float ...$parts): string => implode('|', $parts),
            'half' => static fn(int $value): float => $value / 2,
            'size' => static fn(): string => 'unsized',
            'instant' => self::instant(...),
            'instants' => self::instants(...),
        ]));

        return $configuration;
    }

    /**
     * @param array<array-key, DateTimeImmutable> $values
     */
    private static function instants(array $values): string
    {
        $instants = [];
        foreach ($values as $key => $value) {
            $instants[] = (is_string($key) ? $key . '=' : '') . self::instant($value);
        }

        return implode(',', $instants);
    }

    private static function instant(mixed $value): string
    {
        return $value instanceof DateTimeImmutable ? $value->format('Y-m-d\TH:i:s.uP') : 'not a date';
    }
}
