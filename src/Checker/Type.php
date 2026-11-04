<?php

declare(strict_types=1);

namespace Cel\Checker;

use function count;

/**
 * The type a variable is declared as, or a part of an expression produces.
 *
 * An object type is a name. Its fields come from the {@see TypeProvider} the
 * checker is given, so a type that refers to itself, such as an order whose
 * customer holds orders, stays finite.
 *
 * @api
 */
final readonly class Type
{
    /**
     * @param list<Type> $members
     */
    private function __construct(
        public TypeKind $kind,
        public string $name = '',
        public null|Type $element = null,
        public array $members = [],
    ) {}

    public static function dyn(): self
    {
        return new self(TypeKind::Dyn);
    }

    public static function scalar(string $name): self
    {
        return new self(TypeKind::Scalar, $name);
    }

    public static function list(Type $element): self
    {
        return new self(TypeKind::List, element: $element);
    }

    public static function map(Type $value): self
    {
        return new self(TypeKind::Map, element: $value);
    }

    public static function object(string $name): self
    {
        return new self(TypeKind::Object, $name);
    }

    /**
     * One of several types. A single member is that member, and a dyn member
     * makes the whole union dyn, because dyn already admits every read.
     *
     * @param non-empty-list<Type> $members
     */
    public static function union(array $members): self
    {
        foreach ($members as $member) {
            if (TypeKind::Dyn === $member->kind) {
                return $member;
            }
        }

        return 1 === count($members) ? $members[0] : new self(TypeKind::Union, members: $members);
    }
}
