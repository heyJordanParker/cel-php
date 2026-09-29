<?php

declare(strict_types=1);

namespace Cel\Extension\Callable\Handler;

use Cel\Exception\IncompatibleValueTypeException;
use Cel\Function\FunctionOverloadHandlerInterface;
use Cel\Syntax\Member\CallExpression;
use Cel\Util\MapKeyUtil;
use Cel\Value\ListValue;
use Cel\Value\MapValue;
use Cel\Value\OptionalValue;
use Cel\Value\TimestampValue;
use Cel\Value\Value;
use Override;
use Psl\DateTime\DateTime;
use Psl\DateTime\Timezone;

use function array_map;

/**
 * Runs a host callable for a {@see \Cel\Extension\Callable\CallableFunction}.
 *
 * Arguments arrive as values and a value is expected back, while the callable
 * speaks native PHP, so arguments are unwrapped on the way in and the result is
 * wrapped on the way out. A timestamp arrives as a `DateTimeImmutable` in UTC,
 * wherever it sits in an argument.
 */
final readonly class CallableHandler implements FunctionOverloadHandlerInterface
{
    /**
     * @param callable $callable Receives native PHP arguments and returns a native PHP value.
     */
    public function __construct(
        private mixed $callable,
    ) {}

    /**
     * @param CallExpression $call The call expression.
     * @param list<Value> $arguments The function arguments.
     *
     * @return Value The resulting value.
     *
     * @throws IncompatibleValueTypeException If the callable returns a value no CEL type holds.
     */
    #[Override]
    public function __invoke(CallExpression $call, array $arguments): Value
    {
        return Value::from(($this->callable)(...array_map(self::native(...), $arguments)));
    }

    private static function native(Value $value): mixed
    {
        if ($value instanceof TimestampValue) {
            return DateTime::fromTimestamp($value->value, Timezone::UTC)->toStdlib();
        }

        if ($value instanceof ListValue) {
            return array_map(self::native(...), $value->value);
        }

        if ($value instanceof MapValue) {
            $native = [];
            foreach ($value->value as $key => $item) {
                $native[MapKeyUtil::keyToRaw($key)] = self::native($item);
            }

            return $native;
        }

        if ($value instanceof OptionalValue) {
            return null === $value->value ? null : self::native($value->value);
        }

        return $value->getRawValue();
    }
}
