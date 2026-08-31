<?php

declare(strict_types=1);

namespace Cel\Tests\Runtime;

use Cel\Exception\EvaluationException;
use Cel\Runtime\Configuration;
use Cel\Value\BooleanValue;
use Cel\Value\FloatValue;
use Cel\Value\Value;
use Override;

/**
 * Covers mixed integer and float operands under every numeric operator.
 *
 * Overloads dispatch on the exact operand kind pair, so before the promotion an
 * operator declared only for identical kinds had no handler for `1 == 1.0` and
 * the operation resolved to nothing at all.
 */
final class NumericPromotionTest extends RuntimeTestCase
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
        // --- Equality ---

        yield 'integer equals float' => ['1 == 1.0', [], new BooleanValue(true)];
        yield 'float equals integer' => ['1.0 == 1', [], new BooleanValue(true)];
        yield 'integer does not equal float' => ['1 == 1.5', [], new BooleanValue(false)];
        yield 'integer not-equals float' => ['1 != 1.5', [], new BooleanValue(true)];
        yield 'unsigned integer equals float' => ['uint(1) == 1.0', [], new BooleanValue(true)];
        yield 'float equals unsigned integer' => ['1.0 == uint(1)', [], new BooleanValue(true)];

        // --- Comparison ---

        yield 'integer less than float' => ['1 < 1.5', [], new BooleanValue(true)];
        yield 'float greater than integer' => ['2.5 > 2', [], new BooleanValue(true)];
        yield 'integer less than or equal to float' => ['1 <= 1.0', [], new BooleanValue(true)];
        yield 'integer greater than or equal to float' => ['2 >= 2.0', [], new BooleanValue(true)];
        yield 'unsigned integer less than float' => ['uint(1) < 1.5', [], new BooleanValue(true)];

        // --- Arithmetic ---

        yield 'integer plus float' => ['1 + 0.5', [], new FloatValue(1.5)];
        yield 'float minus integer' => ['2.5 - 1', [], new FloatValue(1.5)];
        yield 'integer times float' => ['2 * 1.5', [], new FloatValue(3.0)];
        yield 'float divided by integer' => ['3.0 / 2', [], new FloatValue(1.5)];
        yield 'unsigned integer plus float' => ['uint(2) + 0.5', [], new FloatValue(2.5)];

        // --- The authored shape this exists for ---
        //
        // A money field reaches an expression as a float and every authored rule
        // compares it against a bare integer literal.

        yield 'float variable compared against an integer literal' => [
            'order.refunded == 0',
            ['order' => ['refunded' => 0.0]],
            new BooleanValue(true),
        ];

        yield 'float variables subtracted from an integer literal' => [
            '10 - order.refunded',
            ['order' => ['refunded' => 2.5]],
            new FloatValue(7.5),
        ];
    }
}
