<?php

declare(strict_types=1);

namespace Cel\Function;

/**
 * A function that accepts any argument list rather than a fixed set of overloads.
 *
 * A call resolves by hashing the exact kind sequence of its arguments, so a
 * function whose implementation is supplied at runtime — a host callable — has
 * no signature to declare ahead of time. Enumerating every kind sequence it
 * might be called with is the alternative, and a sequence missing from that
 * enumeration resolves to no overload and silently yields nothing.
 *
 * The registry consults the handler here when no declared overload matches, so
 * such a function keeps one implementation for every shape it is called with.
 */
interface DynamicFunctionInterface extends FunctionInterface
{
    /**
     * Returns the handler invoked for any argument list.
     */
    public function getHandler(): FunctionOverloadHandlerInterface;
}
