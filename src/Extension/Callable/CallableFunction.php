<?php

declare(strict_types=1);

namespace Cel\Extension\Callable;

use Cel\Extension\Callable\Handler\CallableHandler;
use Cel\Function\DynamicFunctionInterface;
use Cel\Function\FunctionOverloadHandlerInterface;
use Override;

/**
 * Exposes one host callable to expressions under a name.
 *
 * The callable's accepted argument shapes are not known here, so it declares no
 * overloads and serves every call through one handler.
 */
final readonly class CallableFunction implements DynamicFunctionInterface
{
    /**
     * @param non-empty-string $name The name expressions call this function by.
     * @param callable $callable Receives native PHP arguments and returns a native PHP value.
     * @param bool $idempotent Whether the callable returns the same result for the same arguments.
     */
    public function __construct(
        private string $name,
        private mixed $callable,
        private bool $idempotent = false,
    ) {}

    /**
     * @return non-empty-string
     */
    #[Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function isIdempotent(): bool
    {
        return $this->idempotent;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getOverloads(): iterable
    {
        return [];
    }

    #[Override]
    public function getHandler(): FunctionOverloadHandlerInterface
    {
        return new CallableHandler($this->callable);
    }
}
