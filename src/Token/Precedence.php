<?php

declare(strict_types=1);

namespace Cel\Token;

/**
 * Defines the precedence levels for CEL operators.
 *
 * Precedence determines the order in which operators are evaluated. Operators with a lower enum value
 * have higher precedence and are evaluated first.
 */
enum Precedence: int
{
    /**
     * Precedence for member access (`.`), index (`[]`), function calls (`()`), and struct construction (`{}`).
     */
    case Call = 1;

    /**
     * Precedence for unary operators like logical not (`!`) and unary minus (`-`).
     */
    case Unary = 2;

    /**
     * Precedence for multiplicative operators (`*`, `/`, `%`).
     */
    case Multiplicative = 3;

    /**
     * Precedence for additive operators (`+`, `-`).
     */
    case Additive = 4;

    /**
     * Precedence for relational operators (`<`, `<=`, `>`, `>=`, `==`, `!=`, `in`).
     */
    case Relation = 5;

    /**
     * Precedence for the logical AND operator (`&&`).
     */
    case And = 6;

    /**
     * Precedence for the logical OR operator (`||`).
     */
    case Or = 7;

    /**
     * Precedence for the null-or-empty coalesce operator (`??`).
     *
     * Binds looser than logical OR and tighter than the ternary conditional,
     * so `a || b ?? c` groups as `(a || b) ?? c` and `a ?? b ? c : d` groups
     * as `(a ?? b) ? c : d`.
     */
    case Coalesce = 8;

    /**
     * Precedence for the ternary conditional operator (`?:`).
     */
    case Conditional = 9;

    public function getAssociativity(): null|Associativity
    {
        return match ($this) {
            Precedence::Call, Precedence::Multiplicative, Precedence::Coalesce => Associativity::LeftToRight,
            Precedence::Unary, Precedence::Conditional => Associativity::RightToLeft,
            default => null,
        };
    }
}
