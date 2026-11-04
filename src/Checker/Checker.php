<?php

declare(strict_types=1);

namespace Cel\Checker;

use Cel\Syntax\Expression;
use Cel\Syntax\Literal\BytesLiteralExpression;
use Cel\Syntax\Literal\IntegerLiteralExpression;
use Cel\Syntax\Literal\StringLiteralExpression;
use Cel\Syntax\Literal\UnsignedIntegerLiteralExpression;
use Cel\Syntax\Member\CallExpression;
use Cel\Syntax\Member\IdentifierExpression;
use Cel\Syntax\Member\IndexExpression;
use Cel\Syntax\Member\MemberAccessExpression;
use Cel\Syntax\Node;
use Cel\Syntax\ParenthesizedExpression;
use Cel\Template\Template;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function is_int;
use function is_string;

/**
 * Checks the paths an expression reads against the variables a host declares,
 * the check step of cel-go's `Env.Compile`.
 *
 * Every root must be declared, every field must exist on an object, and every
 * item must be read from a value that holds items. A comprehension's variable
 * reads through the collection it walks, so `offers.map(o, o.price)` checks
 * `price` on an item of `offers`. Calls and operators produce dyn, CEL's
 * gradual typing, so only paths are checked.
 *
 * @api
 */
final readonly class Checker
{
    public function __construct(
        private TypeProvider $types,
    ) {}

    /**
     * @param array<string, Type> $variables The type of every variable the expression may read.
     */
    public function check(Expression $expression, array $variables): ExpressionCheck
    {
        $issues = [];
        $type = $this->reach($expression, $variables, [], $issues)['type'];

        return new ExpressionCheck(array_values($issues), $type);
    }

    /**
     * What a node reads: its type, and its path as written, empty when it reads none.
     *
     * @param array<string, Type> $variables
     * @param array<string, array{role: 'item'|'key'|'value', collection: array{type: Type, path: string}}> $scope
     *        Each comprehension variable, with its role and the collection it walks.
     * @param array<string, Issue> $issues
     *
     * @return array{type: Type, path: string}
     */
    private function reach(Node $node, array $variables, array $scope, array &$issues): array
    {
        if ($node instanceof ParenthesizedExpression) {
            return $this->reach($node->expression, $variables, $scope, $issues);
        }

        if ($node instanceof IdentifierExpression) {
            $name = $node->identifier->name;

            if (array_key_exists($name, $scope)) {
                return match ($scope[$name]['role']) {
                    'item' => $this->item($scope[$name]['collection'], null, $issues),
                    'key' => ['type' => Type::dyn(), 'path' => ''],
                    'value' => $scope[$name]['collection'],
                };
            }

            if (array_key_exists($name, $variables)) {
                return ['type' => $variables[$name], 'path' => $name];
            }

            return self::refuse($issues, new Issue(IssueKind::UndeclaredReference, $name, $name));
        }

        if ($node instanceof MemberAccessExpression) {
            return $this->item($this->reach($node->operand, $variables, $scope, $issues), $node->field->name, $issues);
        }

        if ($node instanceof IndexExpression) {
            $operand = $this->reach($node->operand, $variables, $scope, $issues);
            $this->reach($node->index, $variables, $scope, $issues);
            $index = $node->index;

            return $this->item(
                $operand,
                match (true) {
                    $index instanceof IntegerLiteralExpression,
                    $index instanceof UnsignedIntegerLiteralExpression,
                    $index instanceof StringLiteralExpression,
                    $index instanceof BytesLiteralExpression,
                        => $index->getValue(),
                    default => null,
                },
                $issues,
            );
        }

        if ($node instanceof CallExpression && null !== $node->target) {
            $arguments = $node->arguments->elements;
            $roles = Template::COMPREHENSIONS[$node->function->name][count($arguments)] ?? [];
            $bound = array_slice($arguments, 0, count($roles));

            if ([] !== $roles && self::identifiers($bound)) {
                $collection = $this->reach($node->target, $variables, $scope, $issues);

                foreach ($bound as $position => $variable) {
                    if (!$variable instanceof IdentifierExpression) {
                        continue;
                    }

                    $scope[$variable->identifier->name] = [
                        'role' => $roles[$position] ?? 'key',
                        'collection' => $collection,
                    ];
                }

                foreach (array_slice($arguments, count($roles)) as $argument) {
                    $this->reach($argument, $variables, $scope, $issues);
                }

                return 'filter' === $node->function->name ? $collection : ['type' => Type::dyn(), 'path' => ''];
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->reach($child, $variables, $scope, $issues);
        }

        return ['type' => Type::dyn(), 'path' => ''];
    }

    /**
     * Reads one field or item. A string is a field, an integer is a position,
     * and null is every item, as a computed index or a comprehension reads.
     *
     * @param array{type: Type, path: string} $read
     * @param array<string, Issue> $issues
     *
     * @return array{type: Type, path: string}
     */
    private function item(array $read, null|int|string $segment, array &$issues): array
    {
        $type = $this->segment($read['type'], $segment);

        if (null !== $type) {
            return [
                'type' => $type,
                'path' => match (true) {
                    '' === $read['path'] || null === $segment => $read['path'],
                    is_int($segment) => $read['path'] . '[' . $segment . ']',
                    default => $read['path'] . '.' . $segment,
                },
            ];
        }

        return self::refuse(
            $issues,
            null === $segment
                ? new Issue(IssueKind::NotIndexable, $read['path'], '')
                : new Issue(IssueKind::UndefinedField, $read['path'], (string) $segment),
        );
    }

    /**
     * The type one segment reads from a type, or null when the type refuses it.
     */
    private function segment(Type $type, null|int|string $segment): null|Type
    {
        return match ($type->kind) {
            TypeKind::Dyn => $type,
            TypeKind::Map => $type->element,
            TypeKind::List => is_string($segment) ? null : $type->element,
            TypeKind::Object => null === $segment ? null : $this->types->fieldType($type->name, (string) $segment),
            TypeKind::Scalar => null,
            TypeKind::Union => $this->unionSegment($type->members, $segment),
        };
    }

    /**
     * @param list<Type> $members
     */
    private function unionSegment(array $members, null|int|string $segment): null|Type
    {
        $admitted = [];
        foreach ($members as $member) {
            $read = $this->segment($member, $segment);

            if (null !== $read) {
                $admitted[] = $read;
            }
        }

        return [] === $admitted ? null : Type::union($admitted);
    }

    /**
     * @param list<Expression> $variables
     */
    private static function identifiers(array $variables): bool
    {
        foreach ($variables as $variable) {
            if (!$variable instanceof IdentifierExpression) {
                return false;
            }
        }

        return true;
    }

    /**
     * Records an issue once, and answers dyn so the rest of the read checks nothing more.
     *
     * @param array<string, Issue> $issues
     *
     * @return array{type: Type, path: string}
     */
    private static function refuse(array &$issues, Issue $issue): array
    {
        $issues[$issue->kind->name . "\0" . $issue->path . "\0" . $issue->name] = $issue;

        return ['type' => Type::dyn(), 'path' => ''];
    }
}
