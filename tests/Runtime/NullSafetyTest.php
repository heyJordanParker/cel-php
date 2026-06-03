<?php

declare(strict_types=1);

namespace Cel\Tests\Runtime;

use Cel\Exception\EvaluationException;
use Cel\Runtime\Configuration;
use Cel\Value\BooleanValue;
use Cel\Value\IntegerValue;
use Cel\Value\NullValue;
use Cel\Value\StringValue;
use Cel\Value\Value;
use Override;

/**
 * Covers the two forgiving language behaviors layered onto the fork:
 *
 * 1. Null-safe member, field, and index access — accessing a member on a null
 *    or missing value yields null and cascades, rather than throwing.
 * 2. The `??` null-or-empty coalesce operator.
 */
final class NullSafetyTest extends RuntimeTestCase
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
        // --- Null-safe access ---

        yield 'member access on null operand yields null' => [
            'customer.firstName',
            ['customer' => null],
            new NullValue(),
        ];

        yield 'missing field yields null' => [
            'customer.firstName',
            ['customer' => ['lastName' => 'Doe']],
            new NullValue(),
        ];

        yield 'null somewhere in a chain yields null, not an error' => [
            'a.b.c',
            ['a' => ['b' => null]],
            new NullValue(),
        ];

        yield 'missing link mid-chain cascades to null' => [
            'a.b.c',
            ['a' => ['x' => 1]],
            new NullValue(),
        ];

        yield 'present chain returns the leaf value' => [
            'a.b.c',
            ['a' => ['b' => ['c' => 'deep']]],
            new StringValue('deep'),
        ];

        yield 'index into null yields null' => [
            'a[0]',
            ['a' => null],
            new NullValue(),
        ];

        yield 'out of bounds list index yields null' => [
            'a[5]',
            ['a' => ['only']],
            new NullValue(),
        ];

        yield 'missing map key by index yields null' => [
            'a["missing"]',
            ['a' => ['present' => 1]],
            new NullValue(),
        ];

        // --- `??` coalesce ---

        yield 'coalesce falls back on null' => [
            'a ?? \'fallback\'',
            ['a' => null],
            new StringValue('fallback'),
        ];

        yield 'coalesce falls back on missing field' => [
            'customer.firstName ?? \'there\'',
            ['customer' => ['lastName' => 'Doe']],
            new StringValue('there'),
        ];

        yield 'coalesce falls back on empty string' => [
            'a ?? \'fallback\'',
            ['a' => ''],
            new StringValue('fallback'),
        ];

        yield 'coalesce falls back on empty list' => [
            'a ?? \'fallback\'',
            ['a' => []],
            new StringValue('fallback'),
        ];

        yield 'coalesce does not fall back on a real value' => [
            'a ?? \'fallback\'',
            ['a' => 'value'],
            new StringValue('value'),
        ];

        yield 'coalesce does not fall back on zero' => [
            'a ?? 99',
            ['a' => 0],
            new IntegerValue(0),
        ];

        yield 'coalesce does not fall back on false' => [
            'a ?? true',
            ['a' => false],
            new BooleanValue(false),
        ];

        yield 'coalesce chains left to right, first non-empty wins' => [
            'a ?? b ?? c',
            ['a' => null, 'b' => '', 'c' => 'third'],
            new StringValue('third'),
        ];

        yield 'coalesce chain stops at first real value' => [
            'a ?? b ?? c',
            ['a' => null, 'b' => 'second', 'c' => 'third'],
            new StringValue('second'),
        ];

        // --- precedence against comparison ---
        // `??` binds looser than `==`, so `a == b ?? c` groups as `(a == b) ?? c`.
        // With a == b true, the comparison yields a real boolean and `?? c` is
        // not taken.

        yield 'coalesce binds looser than comparison' => [
            'a == b ?? \'fallback\'',
            ['a' => 1, 'b' => 1],
            new BooleanValue(true),
        ];

        // --- the worked sample from the brief ---

        yield 'sample: name present' => [
            'customer.firstName ?? \'there\'',
            ['customer' => ['firstName' => 'Ada']],
            new StringValue('Ada'),
        ];

        yield 'sample: customer null' => [
            'customer.firstName ?? \'there\'',
            ['customer' => null],
            new StringValue('there'),
        ];

        yield 'sample: firstName empty' => [
            'customer.firstName ?? \'there\'',
            ['customer' => ['firstName' => '']],
            new StringValue('there'),
        ];
    }
}
