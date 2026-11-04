<?php

declare(strict_types=1);

namespace Cel\Checker;

/**
 * @api
 */
enum IssueKind
{
    /** The expression reads a variable nothing declares. */
    case UndeclaredReference;

    /** The expression reads a field its value does not have. */
    case UndefinedField;

    /** The expression reads an item of a value that holds no items. */
    case NotIndexable;
}
