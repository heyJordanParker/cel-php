<?php

declare(strict_types=1);

namespace Cel\Checker;

/**
 * What one check found: the reads it refused, and the type the expression
 * produces. An expression with no issues is valid against its declarations.
 *
 * @api
 */
final readonly class ExpressionCheck
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        public array $issues,
        public Type $type,
    ) {}
}
