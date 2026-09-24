<?php

declare(strict_types=1);

namespace Cel\Tests\Fixture;

use Cel\Exception\IncompatibleValueTypeException;
use Cel\Exception\InternalException;
use Cel\Message\FieldProviderInterface;
use Cel\Value\MessageValue;
use Cel\Value\Value;
use Override;

use function array_key_exists;

/**
 * A message that answers its fields one at a time and records every field the
 * runtime reads, used to prove a field is read only when an expression reaches
 * it.
 */
final class FieldProviderMessage implements FieldProviderInterface
{
    /**
     * @var list<string>
     */
    public array $reads = [];

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        private readonly array $fields,
    ) {}

    #[Override]
    public function hasField(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    /**
     * @throws IncompatibleValueTypeException If the field holds a value the runtime cannot represent.
     */
    #[Override]
    public function getField(string $name): null|Value
    {
        $this->reads[] = $name;

        return array_key_exists($name, $this->fields) ? Value::from($this->fields[$name]) : null;
    }

    #[Override]
    public function toCelValue(): Value
    {
        return new MessageValue($this, []);
    }

    /**
     * @throws InternalException Always: this fixture is not constructible from CEL fields.
     */
    #[Override]
    public static function fromCelFields(array $fields): static
    {
        throw InternalException::forMessage('FieldProviderMessage cannot be constructed from CEL fields');
    }
}
