<?php

declare(strict_types=1);

namespace Cel\Optimizer\Optimization;

use Cel\Syntax\Binary\BinaryExpression;
use Cel\Syntax\Binary\BinaryOperatorKind;
use Cel\Syntax\Expression;
use Cel\Syntax\Literal\BoolLiteralExpression;
use Override;

/**
 * Replaces a logical AND (&&) or OR (||) with the constant that decides it.
 *
 * Optimizations:
 *
 * - `expr && false` -> `false`
 * - `expr || true`  -> `true`
 *
 * The runtime gives the same answer whatever the other side holds, an error included. A
 * constant that does not decide the result is left alone: `expr && true` is an error when
 * `expr` is not a boolean, and replacing it with `expr` would change the result.
 *
 * @api
 */
final readonly class ShortCircuitBooleanOptimization implements OptimizationInterface
{
    #[Override]
    public function apply(Expression $expression): null|Expression
    {
        if (!$expression instanceof BinaryExpression) {
            return null;
        }

        $deciding = match ($expression->operator->kind) {
            BinaryOperatorKind::And => false,
            BinaryOperatorKind::Or => true,
            default => null,
        };
        if ($deciding === null) {
            return null;
        }

        foreach ([$expression->left, $expression->right] as $side) {
            if ($side instanceof BoolLiteralExpression && $side->value === $deciding) {
                return $side;
            }
        }

        return null;
    }
}
