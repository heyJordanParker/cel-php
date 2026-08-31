<?php

declare(strict_types=1);

namespace Cel\Extension\Core\BinaryOperator\Handler;

use Cel\Exception\InternalException;
use Cel\Operator\BinaryOperatorOverloadHandlerInterface;
use Cel\Syntax\Binary\BinaryExpression;
use Cel\Value\FloatValue;
use Cel\Value\IntegerValue;
use Cel\Value\UnsignedIntegerValue;
use Cel\Value\Value;
use Override;

/**
 * Reads a mixed integer and float operand pair as two floats, then defers to the
 * float handler for the operator.
 *
 * Overloads are dispatched on the exact kind pair, so an operator declared only
 * for identical kinds has no handler at all for `1 == 1.0` or `total - 0.5`, and
 * the operation resolves to nothing. CEL defines its numeric comparisons across
 * int, uint and double, so the promotion is what the language already means.
 */
final readonly class NumericPromotionHandler implements BinaryOperatorOverloadHandlerInterface
{
    public function __construct(
        private BinaryOperatorOverloadHandlerInterface $float,
    ) {}

    /**
     * @param BinaryExpression $expression The binary expression being evaluated.
     * @param Value $left The evaluated left operand.
     * @param Value $right The evaluated right operand.
     *
     * @return Value The result of the binary operation.
     *
     * @throws InternalException If operand type assertion fails.
     */
    #[Override]
    public function __invoke(BinaryExpression $expression, Value $left, Value $right): Value
    {
        return ($this->float)($expression, self::promote($left), self::promote($right));
    }

    private static function promote(Value $value): Value
    {
        if ($value instanceof IntegerValue || $value instanceof UnsignedIntegerValue) {
            return new FloatValue((float) $value->value);
        }

        return $value;
    }
}
