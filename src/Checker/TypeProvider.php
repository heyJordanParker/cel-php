<?php

declare(strict_types=1);

namespace Cel\Checker;

/**
 * Answers the fields of the object types a host declares, the way cel-go's
 * `TypeProvider` answers `FindStructFieldType`.
 *
 * @api
 */
interface TypeProvider
{
    /**
     * The type of `$field` on the object type named `$object`, or null when that
     * object declares no such field.
     */
    public function fieldType(string $object, string $field): null|Type;
}
