<?php

declare(strict_types=1);

namespace Cel\Tests\Optimizer;

use Cel\CommonExpressionLanguage;
use Cel\Optimizer\Optimization\ConditionalSimplificationOptimization;
use Cel\Optimizer\Optimization\ConstantFoldingOptimization;
use Cel\Optimizer\Optimizer;
use Cel\Parser\Parser;
use Cel\Runtime\Runtime;
use Cel\Value\Value;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class NewOptimizationsTest extends TestCase
{
    public function testConstantFoldingOptimization(): void
    {
        $cel = CommonExpressionLanguage::default();

        // Test arithmetic constant folding
        $expr = $cel->parseString('1 + 2 * 3');
        $receipt = $cel->run($expr);
        static::assertSame(7, $receipt->result->getRawValue());

        // Test nested constant folding
        $expr = $cel->parseString('(1 + 1) * (2 + 2)');
        $receipt = $cel->run($expr);
        static::assertSame(8, $receipt->result->getRawValue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCodeTheOptimizerMustNotChange(): iterable
    {
        foreach ([
            'true * 1',
            '1 * true',
            '"a" + 0',
            '"a" - 0',
            '"a" / 1',
            'x * 0',
            'x + 0',
            '5 * 1.0',
            '!!1',
            '--"a"',
            '1 && true',
            'true && 1',
            '1 || false',
            'false || 1',
            'null / 1',
            'missing && false',
            'missing || true',
            'x * 1 > 2',
            '!!(x > 1)',
        ] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('provideCodeTheOptimizerMustNotChange')]
    public function testTheOptimizerNeverChangesAResult(string $code): void
    {
        $parsed = Parser::default()->parseString($code);
        $variables = ['x' => 2.5];

        static::assertSame(
            $this->outcome(static fn (): Value => (new Runtime())->run($parsed, $variables)->result),
            $this->outcome(static fn (): Value => (new Runtime())->run(Optimizer::default()->optimize($parsed), $variables)->result),
        );
    }

    /**
     * @param Closure(): Value $run
     */
    private function outcome(Closure $run): string
    {
        try {
            $result = $run();
        } catch (Throwable $exception) {
            return $exception::class;
        }

        return $result->getType() . ' ' . var_export($result->getRawValue(), true);
    }

    public function testConditionalSimplificationOptimization(): void
    {
        $cel = CommonExpressionLanguage::default();

        // Test true ? x : y
        $expr = $cel->parseString('true ? "yes" : "no"');
        $receipt = $cel->run($expr);
        static::assertSame('yes', $receipt->result->getRawValue());

        // Test false ? x : y
        $expr = $cel->parseString('false ? "yes" : "no"');
        $receipt = $cel->run($expr);
        static::assertSame('no', $receipt->result->getRawValue());
    }

    public function testComplexBenchmarkExpression(): void
    {
        $cel = CommonExpressionLanguage::default();

        $expression = <<<'CEL'
                (
                    // Multiple constant folding opportunities
                    (1 + 2 * 3 - 5) + 0 == 2 &&
                    (10 / 2 + 3 * 1) * 1 > 7 &&

                    // Identity operations
                    account.balance * 1 + 0 >= transaction.withdrawal - 0 &&

                    // Double negations and short-circuit logic
                    !!(account.overdraftProtection || false) &&
                    (true && account.overdraftLimit * 1 >= (transaction.withdrawal - account.balance) + 0) &&

                    // More constant folding
                    (5 + 5 - 3 * 2 + 1) > 3 &&

                    // Conditional simplification and string operations
                    (true ? (account.tier + "" + " customer") : "unknown") != "" &&

                    // Complex boolean expressions with constants
                    (false || (true && (account.premium || false))) &&

                    // More identity operations
                    transaction.fee / 1 + 0 < account.balance / 1 &&

                    // Nested constant expressions
                    ((1 + 1) * (2 + 2) / 2 - 3 + 1) == 2 &&

                    // String concatenation with constants
                    ("" + account.name + "" + " " + account.surname).size() > 0 &&

                    // More short-circuit logic
                    (true || account.suspended) &&
                    (account.verified && true) &&

                    // Additional constant folding in conditionals
                    (2 + 2 == 4 ? account.score : 0) * 1 >= 100 * 1 &&

                    // Complex nested arithmetic
                    ((account.deposits + 0) * 1 - (account.withdrawals - 0)) / 1 +
                    ((transaction.amount * 1 + 0) / 1) > (500 + 500 - 100 * 2) &&

                    // Final validation with multiple optimizations
                    !!(false || true) &&
                    (0 + 1 * account.balance / 1 - 0) >=
                        ((1 + 2 + 3 + 4) * 100 - (5 * 100 + 500)) + 0
                )
                ? account.name.toUpper() + " " + ("APPROVED" + "" + " " + (true ? "PREMIUM" : "STANDARD"))
                : (false ? "ERROR" : "DENIED")
            CEL;

        $environment = [
            'account' => [
                'name' => 'John',
                'surname' => 'Doe',
                'balance' => 1500,
                'overdraftProtection' => true,
                'overdraftLimit' => 2000,
                'tier' => 'gold',
                'premium' => true,
                'suspended' => false,
                'verified' => true,
                'score' => 150,
                'deposits' => 5000,
                'withdrawals' => 3000,
            ],
            'transaction' => [
                'withdrawal' => 700,
                'fee' => 5,
                'amount' => 200,
            ],
        ];

        $expr = $cel->parseString($expression);
        $receipt = $cel->run($expr, $environment);

        // The expression should evaluate to the approval message
        static::assertSame('JOHN APPROVED PREMIUM', $receipt->result->getRawValue());
    }

    public function testOptimizationsAreApplied(): void
    {
        $parser = Parser::default();
        $optimizer = Optimizer::default();
        $expression = $parser->parseString('1 + 2');

        $optimizer->addOptimization(new ConstantFoldingOptimization());
        $optimizer->addOptimization(new ConditionalSimplificationOptimization());

        $optimized = $optimizer->optimize($expression);
        static::assertNotSame($optimized, $expression);
    }
}
