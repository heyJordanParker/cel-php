<?php

declare(strict_types=1);

namespace Cel\Value;

use Cel\Exception\UnsupportedOperationException;
use Cel\Message\FieldProviderInterface;
use Cel\Message\MessageInterface;
use Cel\Message\ZeroValueInterface;
use Override;

/**
 * Represents a message value.
 *
 * @api
 */
final readonly class MessageValue extends Value
{
    /**
     * @param array<string, Value> $fields
     */
    public function __construct(
        public MessageInterface $message,
        public array $fields,
    ) {}

    #[Override]
    public function getKind(): ValueKind
    {
        return ValueKind::Message;
    }

    /**
     * A message is a zero value only when its underlying message opts in via
     * {@see ZeroValueInterface} and reports itself as zero. Messages that do not
     * implement it are never treated as zero values.
     */
    #[Override]
    public function isZeroValue(): bool
    {
        return $this->message instanceof ZeroValueInterface && $this->message->isZeroValue();
    }

    #[Override]
    public function isEqual(Value $other): bool
    {
        if (!$other instanceof MessageValue) {
            return false;
        }

        // A field provider holds no fields to compare, so it equals only itself.
        if ($this->message instanceof FieldProviderInterface) {
            return $this->message === $other->message;
        }

        if ($this->message::class !== $other->message::class) {
            return false;
        }

        foreach ($this->fields as $field => $value) {
            $otherValue = $other->getField($field);

            if (null === $otherValue || !$value->isEqual($otherValue)) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function isLessThan(Value $other): bool
    {
        throw UnsupportedOperationException::forComparison($this, $other);
    }

    #[Override]
    public function isGreaterThan(Value $other): bool
    {
        throw UnsupportedOperationException::forComparison($this, $other);
    }

    #[Override]
    public function getRawValue(): MessageInterface
    {
        return $this->message;
    }

    /**
     * Checks if a field exists, asking a {@see FieldProviderInterface} message
     * itself.
     */
    public function hasField(string $name): bool
    {
        if ($this->message instanceof FieldProviderInterface) {
            return $this->message->hasField($name);
        }

        return isset($this->fields[$name]);
    }

    /**
     * Retrieves a field by name, asking a {@see FieldProviderInterface} message
     * for it at the moment it is read.
     */
    public function getField(string $name): null|Value
    {
        if ($this->message instanceof FieldProviderInterface) {
            return $this->message->getField($name);
        }

        return $this->fields[$name] ?? null;
    }
}
