<?php

declare(strict_types=1);

namespace Cel\Extension\Callable;

use Cel\Extension\ExtensionInterface;
use Override;

/**
 * Registers host callables as expression functions, keyed by the name each is
 * called by.
 *
 * This is the seam for functions an application supplies at runtime — a price
 * formatter, a date formatter — without that application implementing the
 * function and handler contracts itself, or naming a single value type.
 */
final readonly class CallableExtension implements ExtensionInterface
{
    /**
     * @param array<non-empty-string, callable> $functions Callables keyed by function name.
     * @param bool $idempotent Whether the callables return the same result for the same arguments.
     */
    public function __construct(
        private array $functions,
        private bool $idempotent = false,
    ) {}

    /**
     * @inheritDoc
     */
    #[Override]
    public function getFunctions(): array
    {
        $functions = [];
        foreach ($this->functions as $name => $callable) {
            $functions[] = new CallableFunction($name, $callable, $this->idempotent);
        }

        return $functions;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getBinaryOperatorOverloads(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getUnaryOperatorOverloads(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getMessageTypes(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getValueResolvers(): array
    {
        return [];
    }
}
