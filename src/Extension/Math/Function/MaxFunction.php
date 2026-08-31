<?php

declare(strict_types=1);

namespace Cel\Extension\Math\Function;

use Cel\Extension\Math\Function\Handler\MaxFunction\ListHandler;
use Cel\Extension\Math\Function\Handler\MaxFunction\ScalarHandler;
use Cel\Function\FunctionInterface;
use Cel\Value\ValueKind;
use Override;

/**
 * @internal
 */
final readonly class MaxFunction implements FunctionInterface
{
    #[Override]
    public function getName(): string
    {
        return 'max';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function isIdempotent(): bool
    {
        return true;
    }

    #[Override]
    public function getOverloads(): iterable
    {
        yield [ValueKind::List] => new ListHandler();

        // The two-argument form, `max(0.0, discount)`, floors one value against
        // another. A null operand is not accepted: `min` and `max` reject a
        // non-number inside a list, and the two-argument form holds the same
        // line. An operand that may be absent is given a value by the caller,
        // with `max(0.0, discount ?? 0.0)`.
        $scalar = new ScalarHandler();
        $kinds = [ValueKind::Integer, ValueKind::Float];
        foreach ($kinds as $left) {
            foreach ($kinds as $right) {
                yield [$left, $right] => $scalar;
            }
        }
    }
}
