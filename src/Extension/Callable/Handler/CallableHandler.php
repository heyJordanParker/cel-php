<?php

declare(strict_types=1);

namespace Cel\Extension\Callable\Handler;

use Cel\Function\FunctionOverloadHandlerInterface;
use Cel\Syntax\Member\CallExpression;
use Cel\Value\Value;
use Override;

use function array_map;

/**
 * Runs a host callable for a {@see \Cel\Extension\Callable\CallableFunction}.
 *
 * Arguments arrive as values and a value is expected back, while the callable
 * speaks native PHP, so arguments are unwrapped on the way in and the result is
 * wrapped on the way out.
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
     */
    #[Override]
    public function __invoke(CallExpression $call, array $arguments): Value
    {
        $native = array_map(static fn(Value $argument): mixed => $argument->getRawValue(), $arguments);

        return Value::from(($this->callable)(...$native));
    }
}
