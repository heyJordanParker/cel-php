<?php

declare(strict_types=1);

namespace Cel\Extension\Math\Function\Handler\MaxFunction;

use Cel\Exception\EvaluationException;
use Cel\Exception\InternalException;
use Cel\Function\FunctionOverloadHandlerInterface;
use Cel\Syntax\Member\CallExpression;
use Cel\Value\FloatValue;
use Cel\Value\IntegerValue;
use Cel\Value\Value;
use Override;
use Psl\Str;

/** The larger of two numbers, the two-argument form of `max`. */
final readonly class ScalarHandler implements FunctionOverloadHandlerInterface
{
    /**
     * @param CallExpression $call The call expression.
     * @param list<Value> $arguments The function arguments.
     *
     * @return FloatValue|IntegerValue The larger operand.
     *
     * @throws EvaluationException
     * @throws InternalException
     */
    #[Override]
    public function __invoke(CallExpression $call, array $arguments): FloatValue|IntegerValue
    {
        $left = $arguments[0] ?? null;
        $right = $arguments[1] ?? null;

        if (
            (!$left instanceof IntegerValue && !$left instanceof FloatValue)
            || (!$right instanceof IntegerValue && !$right instanceof FloatValue)
        ) {
            throw new EvaluationException(
                Str\format(
                    'max() only supports integers and floats, got `%s` and `%s`',
                    $left?->getType() ?? 'null',
                    $right?->getType() ?? 'null',
                ),
                $call->getSpan(),
            );
        }

        return $left->value >= $right->value ? $left : $right;
    }
}
