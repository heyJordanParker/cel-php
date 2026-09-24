<?php

declare(strict_types=1);

namespace Cel\Message;

use Cel\Value\Value;

/**
 * A message that answers its own fields, one at a time.
 *
 * A plain {@see MessageInterface} hands the runtime every field up front, in the
 * `MessageValue` it builds. A field provider hands over only itself, as
 * `new MessageValue($this, [])`, and the runtime asks it for a field at the
 * moment an expression reaches that field. A field no expression reads is never
 * computed, and the object that went in is the object `filter`, `map`, and every
 * other pass-through hand back.
 *
 * Because it carries no fields to compare, a field-provider message equals only
 * itself.
 *
 * @api
 */
interface FieldProviderInterface extends MessageInterface
{
    /**
     * Indicates whether the message has the named field. Answers `has()`.
     */
    public function hasField(string $name): bool;

    /**
     * Returns the named field's value, or null when the message has no such field.
     */
    public function getField(string $name): null|Value;
}
