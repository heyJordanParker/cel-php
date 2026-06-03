<?php

declare(strict_types=1);

namespace Cel\Tests\Parser;

use Cel\Parser\Parser;
use Cel\Syntax\Binary\BinaryExpression;
use Cel\Syntax\Binary\BinaryOperatorKind;
use Cel\Syntax\ConditionalExpression;
use Cel\Syntax\Expression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the grammar decisions for the `??` coalesce operator so they can be
 * checked against the parallel cel-js fork:
 *
 * - Token: `??` (`TokenKind::DoubleQuestion`), `BinaryOperatorKind::Coalesce`.
 * - Associativity: left-to-right (`a ?? b ?? c` => `(a ?? b) ?? c`).
 * - Precedence: looser than logical OR, tighter than the ternary conditional.
 */
final class CoalesceParseTest extends TestCase
{
    /**
     * @param callable(TestCase, Expression): void $asserter
     */
    #[DataProvider('provideCases')]
    public function testParse(string $source, callable $asserter): void
    {
        $expression = new Parser()->parseString($source);
        $asserter($this, $expression);
    }

    /**
     * @return iterable<string, array{string, callable(TestCase, Expression): void}>
     */
    public static function provideCases(): iterable
    {
        yield 'simple coalesce' => [
            'a ?? b',
            static function (TestCase $test, Expression $expr): void {
                $test->assertInstanceOf(BinaryExpression::class, $expr);
                $test->assertSame(BinaryOperatorKind::Coalesce, $expr->operator->kind);
            },
        ];

        yield 'left-associative chain: a ?? b ?? c => (a ?? b) ?? c' => [
            'a ?? b ?? c',
            static function (TestCase $test, Expression $expr): void {
                $test->assertInstanceOf(BinaryExpression::class, $expr);
                $test->assertSame(BinaryOperatorKind::Coalesce, $expr->operator->kind);
                // The left child is the nested `a ?? b`; the right child is `c`.
                $test->assertInstanceOf(BinaryExpression::class, $expr->left);
                $test->assertSame(BinaryOperatorKind::Coalesce, $expr->left->operator->kind);
            },
        ];

        yield 'binds looser than OR: a || b ?? c => (a || b) ?? c' => [
            'a || b ?? c',
            static function (TestCase $test, Expression $expr): void {
                $test->assertInstanceOf(BinaryExpression::class, $expr);
                $test->assertSame(BinaryOperatorKind::Coalesce, $expr->operator->kind);
                $test->assertInstanceOf(BinaryExpression::class, $expr->left);
                $test->assertSame(BinaryOperatorKind::Or, $expr->left->operator->kind);
            },
        ];

        yield 'binds looser than comparison: a == b ?? c => (a == b) ?? c' => [
            'a == b ?? c',
            static function (TestCase $test, Expression $expr): void {
                $test->assertInstanceOf(BinaryExpression::class, $expr);
                $test->assertSame(BinaryOperatorKind::Coalesce, $expr->operator->kind);
                $test->assertInstanceOf(BinaryExpression::class, $expr->left);
                $test->assertSame(BinaryOperatorKind::Equal, $expr->left->operator->kind);
            },
        ];

        yield 'binds tighter than ternary: a ?? b ? c : d => (a ?? b) ? c : d' => [
            'a ?? b ? c : d',
            static function (TestCase $test, Expression $expr): void {
                $test->assertInstanceOf(ConditionalExpression::class, $expr);
                $test->assertInstanceOf(BinaryExpression::class, $expr->condition);
                $test->assertSame(BinaryOperatorKind::Coalesce, $expr->condition->operator->kind);
            },
        ];
    }
}
