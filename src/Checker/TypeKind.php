<?php

declare(strict_types=1);

namespace Cel\Checker;

/**
 * @api
 */
enum TypeKind
{
    /** Any value. A read through it is never refused, CEL's gradual typing. */
    case Dyn;

    /** A value with no members and no items, such as a string or a number. */
    case Scalar;

    /** Items in order, read by position. */
    case List;

    /** Values by key, read by any key. */
    case Map;

    /** Fields by name, read only by a name its {@see TypeProvider} declares. */
    case Object;

    /** One of several types. A read passes when any of them admits it. */
    case Union;
}
