<?php

declare(strict_types=1);

namespace Cel\Checker;

/**
 * One read the checker refused.
 *
 * `$path` is the read up to the refusal, written the way the author wrote it,
 * and an item a comprehension walks reads as its collection: `offers.map(o,
 * o.nope)` is refused at `offers`. `$name` is the variable or the field that
 * is missing, and is empty for {@see IssueKind::NotIndexable}.
 *
 * @api
 */
final readonly class Issue
{
    public function __construct(
        public IssueKind $kind,
        public string $path,
        public string $name,
    ) {}
}
