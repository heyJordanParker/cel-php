<?php

declare(strict_types=1);

namespace Cel\Extension\Math\Function\Handler\SumFunction;

use Cel\Exception\EvaluationException;
use Cel\Exception\InternalException;
use Cel\Function\FunctionOverloadHandlerInterface;
use Cel\Syntax\Member\CallExpression;
use Cel\Util\ArgumentsUtil;
use Cel\Value\FloatValue;
use Cel\Value\IntegerValue;
use Cel\Value\ListValue;
use Cel\Value\Value;
use Override;

use function sprintf;

/**
 * @internal
 */
final readonly class ListHandler implements FunctionOverloadHandlerInterface
{
    /**
     * @param CallExpression $call The call expression.
     * @param list<Value> $arguments The function arguments.
     *
     * @return FloatValue|IntegerValue The resulting value.
     *
     * @throws EvaluationException
     * @throws InternalException
     */
    #[Override]
    public function __invoke(CallExpression $call, array $arguments): FloatValue|IntegerValue
    {
        $list = ArgumentsUtil::get($arguments, 0, ListValue::class);
        if ([] === $list->value) {
            return new IntegerValue(0);
        }

        // A list of prices totals a price, so a float anywhere in the list makes
        // the total a float; an all-integer list still totals an integer.
        $total = 0;
        $isFloat = false;
        foreach ($list->value as $item) {
            if ($item instanceof IntegerValue) {
                $total += $item->value;

                continue;
            }

            if ($item instanceof FloatValue) {
                $total += $item->value;
                $isFloat = true;

                continue;
            }

            throw new EvaluationException(
                sprintf('sum() only supports lists of integers and floats, got `%s`', $item->getType()),
                $call->getSpan(),
            );
        }

        if ($isFloat) {
            return new FloatValue((float) $total);
        }

        return new IntegerValue((int) $total);
    }
}
