<?php

declare(strict_types=1);

namespace Cel\Tests\Checker;

use Cel\Checker\Checker;
use Cel\Checker\Type;
use Cel\Checker\TypeKind;
use Cel\Checker\TypeProvider;
use Cel\Parser\Parser;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The checker contract.
 *
 * `checker.json` is the one case table every implementation of this checker
 * runs, so an expression is refused the same in every host that checks it.
 */
final class CheckerTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: string, 2: string}> $issues
     */
    #[DataProvider('cases')]
    public function testCheck(string $expression, array $issues, mixed $type): void
    {
        $table = self::table();
        $checker = new Checker(new class($table['objects']) implements TypeProvider {
            /**
             * @param array<string, array<string, mixed>> $objects
             */
            public function __construct(
                private array $objects,
            ) {}

            #[Override]
            public function fieldType(string $object, string $field): null|Type
            {
                return isset($this->objects[$object][$field])
                    ? CheckerTest::type($this->objects[$object][$field])
                    : null;
            }
        });

        $check = $checker->check(
            Parser::default()->parseString($expression),
            array_map(self::type(...), $table['variables']),
        );

        $refused = [];
        foreach ($check->issues as $issue) {
            $refused[] = [$issue->kind->name, $issue->path, $issue->name];
        }

        static::assertSame($issues, $refused);
        static::assertSame($type, self::encode($check->type));
    }

    /**
     * @return iterable<string, array{string, list<array{0: string, 1: string, 2: string}>, mixed}>
     */
    public static function cases(): iterable
    {
        foreach (self::table()['cases'] as $case) {
            yield $case['expression'] => [$case['expression'], $case['issues'], $case['type']];
        }
    }

    public static function type(mixed $encoded): Type
    {
        if (is_string($encoded)) {
            return 'dyn' === $encoded ? Type::dyn() : Type::scalar($encoded);
        }

        /** @var array{list?: mixed, map?: mixed, object?: string, union?: non-empty-list<mixed>} $encoded */
        return match (true) {
            isset($encoded['list']) => Type::list(self::type($encoded['list'])),
            isset($encoded['map']) => Type::map(self::type($encoded['map'])),
            isset($encoded['object']) => Type::object($encoded['object']),
            default => Type::union(array_map(self::type(...), $encoded['union'] ?? [Type::dyn()])),
        };
    }

    private static function encode(Type $type): mixed
    {
        return match ($type->kind) {
            TypeKind::Dyn => 'dyn',
            TypeKind::Scalar => $type->name,
            TypeKind::List => ['list' => self::encode($type->element ?? Type::dyn())],
            TypeKind::Map => ['map' => self::encode($type->element ?? Type::dyn())],
            TypeKind::Object => ['object' => $type->name],
            TypeKind::Union => ['union' => array_map(self::encode(...), $type->members)],
        };
    }

    /**
     * @return array{
     *     objects: array<string, array<string, mixed>>,
     *     variables: array<string, mixed>,
     *     cases: list<array{expression: string, issues: list<array{0: string, 1: string, 2: string}>, type: mixed}>,
     * }
     */
    private static function table(): array
    {
        /** @var array{objects: array<string, array<string, mixed>>, variables: array<string, mixed>, cases: list<array{expression: string, issues: list<array{0: string, 1: string, 2: string}>, type: mixed}>} */
        return json_decode((string) file_get_contents(__DIR__ . '/checker.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
