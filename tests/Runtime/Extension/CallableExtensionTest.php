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
use Override;

use function count;
use function implode;
use function is_array;

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
            'label' => static fn(mixed ...$parts): string => implode('|', $parts),
            'half' => static fn(mixed $value): float => ((float) $value) / 2,
            'size' => static fn(mixed $value): string => 'unsized',
        ]));

        return $configuration;
    }
}
