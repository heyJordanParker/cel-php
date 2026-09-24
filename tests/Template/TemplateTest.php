<?php

declare(strict_types=1);

namespace Cel\Tests\Template;

use Cel\Runtime\Configuration;
use Cel\Template\Template;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The template contract.
 *
 * Every case here is asserted by the other implementations of this language
 * too, so text authored once resolves the same wherever it renders.
 */
final class TemplateTest extends TestCase
{
    private Template $template;

    private Template $withChains;

    protected function setUp(): void
    {
        $this->template = new Template(new Configuration());
        $this->withChains = new Template(new Configuration(), enableChains: true);
    }

    // --- Rendering a whole binding ---

    public function testWholeBindingKeepsItsValueType(): void
    {
        static::assertSame(12, $this->template->render('{{ order.total }}', ['order' => ['total' => 12]]));
    }

    public function testWholeExecutableBindingKeepsItsValueType(): void
    {
        static::assertSame(12, $this->template->render('{{{ order.total }}}', ['order' => ['total' => 12]]));
    }

    public function testWholeBindingToleratesSurroundingWhitespace(): void
    {
        static::assertSame(3, $this->template->render('  {{ total }}  ', ['total' => 3]));
    }

    public function testValueWithoutABindingRendersUnchanged(): void
    {
        static::assertSame('plain text', $this->template->render('plain text', []));
    }

    public function testAnArrayOfValuesIsWalked(): void
    {
        static::assertSame(
            [2, 'x 2'],
            $this->template->render(['{{ a }}', 'x {{ a }}'], ['a' => 2]),
        );
    }

    // --- Rendering bindings inside text ---

    public function testBindingSurroundedByTextInterpolates(): void
    {
        static::assertSame('Hi Ada!', $this->template->render('Hi {{ name }}!', ['name' => 'Ada']));
    }

    public function testSeveralBindingsInterpolate(): void
    {
        static::assertSame('1 and 2', $this->template->render('{{ a }} and {{ b }}', ['a' => 1, 'b' => 2]));
    }

    public function testBooleanRendersAsTheStringConversionDoes(): void
    {
        static::assertSame('paid: true', $this->template->render('paid: {{ paid }}', ['paid' => true]));
        static::assertSame('paid: false', $this->template->render('paid: {{ paid }}', ['paid' => false]));
    }

    public function testAbsentValueRendersAsNothing(): void
    {
        static::assertSame('[]', $this->template->render('[{{ missing }}]', []));
    }

    public function testFailingBindingRendersAsNothing(): void
    {
        static::assertSame('[]', $this->template->render('[{{ 1 + "a" }}]', []));
    }

    // --- The escape ---

    public function testEscapedOpenerRendersAsALiteral(): void
    {
        static::assertSame(
            '{{ not a binding }}',
            $this->template->render('\\{{ not a binding }}', []),
        );
    }

    public function testEscapeMarksEveryOpener(): void
    {
        static::assertSame('a \\{{ b }} c', Template::escape('a {{ b }} c'));
    }

    public function testEscapedTextRendersBackToWhatTheAuthorWrote(): void
    {
        $original = 'if (x) {{ y }}';

        static::assertSame($original, $this->template->render(Template::escape($original), []));
    }

    // --- Validity ---

    public function testValidBindingIsValid(): void
    {
        static::assertTrue($this->template->isValid('{{ order.total > 0 }}'));
    }

    public function testValueWithoutABindingIsValid(): void
    {
        static::assertTrue($this->template->isValid('plain'));
    }

    public function testBindingThatDoesNotParseIsInvalid(): void
    {
        static::assertFalse($this->template->isValid('{{ order. }}'));
    }

    public function testEmptyBindingIsInvalid(): void
    {
        static::assertFalse($this->template->isValid('{{}}'));
    }

    public function testOpenerWithoutACloserIsInvalid(): void
    {
        static::assertFalse($this->template->isValid('{{ order.total'));
    }

    public function testEscapedOpenerWithoutACloserIsValid(): void
    {
        static::assertTrue($this->template->isValid('\\{{ order.total'));
    }

    public function testValidityWalksAnArrayOfValues(): void
    {
        static::assertFalse($this->template->isValid(['{{ a }}', '{{ b. }}']));
    }

    // --- Roots ---

    public function testRootsNamesTheVariableABindingReads(): void
    {
        static::assertSame(['order'], $this->template->roots('{{ order.total }}'));
    }

    public function testRootsDoesNotNameAField(): void
    {
        static::assertSame(['a'], $this->template->roots('{{ a.b.c }}'));
    }

    public function testRootsDoesNotNameAFunction(): void
    {
        static::assertSame(['cart'], $this->template->roots('{{ size(cart.items) }}'));
    }

    public function testRootsNamesEveryVariableAcrossBindings(): void
    {
        static::assertSame(['a', 'b'], $this->template->roots('{{ a }} x {{ b.c }}'));
    }

    public function testRootsWalksAnArrayOfValues(): void
    {
        static::assertSame(['a', 'b'], $this->template->roots(['{{ a }}', '{{ b }}']));
    }

    public function testRootsNamesTheReceiverOfAMethodCall(): void
    {
        static::assertContains('article', $this->template->roots('{{ article.links.map(l, l.title) }}'));
    }

    public function testRootsNamesNothingForABindingThatDoesNotParse(): void
    {
        static::assertSame([], $this->template->roots('{{ a. }}'));
    }

    public function testRootsNamesNothingInsideAStringLiteral(): void
    {
        static::assertSame([], $this->template->roots('{{ "order.total" }}'));
    }

    // --- Paths ---

    public function testPathsNamesTheFieldsABindingReads(): void
    {
        static::assertSame(['order' => [['total']]], $this->template->paths('{{ order.total }}'));
    }

    public function testPathsKeepsTheWholeChain(): void
    {
        static::assertSame(['a' => [['b', 'c']]], $this->template->paths('{{ a.b.c }}'));
    }

    public function testPathsReadsALiteralIndexAsItsOwnSegment(): void
    {
        static::assertSame(
            ['funnel' => [['currentStep', 'offers', 1, 'price']]],
            $this->template->paths('{{ funnel.currentStep.offers[1].price }}'),
        );
    }

    public function testPathsStopsAtAnIndexItCannotName(): void
    {
        // Nothing past it can be named, so the collection is the path and the
        // caller reads across every item. The index is a variable of its own.
        static::assertSame(
            ['position' => [[]], 'offers' => [[]]],
            $this->template->paths('{{ offers[position].price }}'),
        );
    }

    public function testPathsNamesTheIndexExpressionsOwnReads(): void
    {
        $paths = $this->template->paths('{{ offers[position.current].price }}');

        static::assertSame([['current']], $paths['position']);
    }

    public function testPathsGivesABareRootTheEmptyChain(): void
    {
        static::assertSame(['order' => [[]]], $this->template->paths('{{ order }}'));
    }

    public function testPathsDoesNotNameAFunction(): void
    {
        static::assertSame(['cart' => [['items']]], $this->template->paths('{{ size(cart.items) }}'));
    }

    public function testPathsDoesNotNameAMethod(): void
    {
        $paths = $this->template->paths('{{ article.links.map(l, l.title) }}');

        static::assertSame([['links']], $paths['article']);
        // The lambda names `l` bare where it declares it, then reads `l.title`.
        static::assertSame([[], ['title']], $paths['l']);
    }

    public function testPathsGathersEveryChainOfOneRoot(): void
    {
        static::assertSame(
            ['order' => [['total'], ['currency']]],
            $this->template->paths('{{ order.total }} {{ order.currency }}'),
        );
    }

    public function testPathsNamesOneChainOnce(): void
    {
        static::assertSame(
            ['order' => [['total']]],
            $this->template->paths('{{ order.total }} {{ order.total }}'),
        );
    }

    public function testPathsWalksAnArrayOfValues(): void
    {
        static::assertSame(
            ['a' => [['b']], 'c' => [['d']]],
            $this->template->paths(['{{ a.b }}', '{{ c.d }}']),
        );
    }

    public function testPathsNamesNothingForABindingThatDoesNotParse(): void
    {
        static::assertSame([], $this->template->paths('{{ a. }}'));
    }

    public function testPathsNamesNothingInsideAStringLiteral(): void
    {
        static::assertSame([], $this->template->paths('{{ "order.total" }}'));
    }

    // --- The chain opener ---

    public function testChainIsOrdinaryTextWhenOff(): void
    {
        static::assertSame(
            'write to @support.team',
            $this->template->render('write to @support.team', ['support' => ['team' => 'x']]),
        );
    }

    public function testChainResolvesWhenTheHostTurnsItOn(): void
    {
        static::assertSame(
            'write to x',
            $this->withChains->render('write to @support.team', ['support' => ['team' => 'x']]),
        );
    }

    public function testWholeChainKeepsItsValueType(): void
    {
        static::assertSame(12, $this->withChains->render('@order.total', ['order' => ['total' => 12]]));
    }

    public function testUnresolvableChainStaysTheTextTheAuthorWrote(): void
    {
        static::assertSame('mail jordan@example.com', $this->withChains->render('mail jordan@example.com', []));
    }

    public function testChainDoesNotOpenInsideAnEmailAddress(): void
    {
        static::assertSame(
            'mail jordan@example.com',
            $this->withChains->render('mail jordan@example.com', ['example' => ['com' => 'x']]),
        );
    }

    public function testRootsNamesAChainVariableOnlyWhenTurnedOn(): void
    {
        static::assertSame([], $this->template->roots('@order.total'));
        static::assertSame(['order'], $this->withChains->roots('@order.total'));
    }

    // --- The stored body ---

    public function testBindingWrapsABody(): void
    {
        static::assertSame('{{ contact.optedIn }}', Template::binding('contact.optedIn'));
    }

    public function testBindingRefusesABodyThatWouldCloseEarly(): void
    {
        static::expectException(InvalidArgumentException::class);

        Template::binding('a }} b');
    }

    public function testBodyReadsBackOutOfAWholeBinding(): void
    {
        static::assertSame('contact.optedIn', Template::body('{{ contact.optedIn }}'));
    }

    public function testBodyRefusesAValueThatInterpolates(): void
    {
        static::expectException(InvalidArgumentException::class);

        Template::body('x {{ a }} y');
    }

    // --- The failure policy ---

    public function testFailedBindingResolvesToNullByDefault(): void
    {
        static::assertNull($this->template->render('{{ 1 + "a" }}', []));
    }

    public function testHostReceivesTheFailure(): void
    {
        $seen = [];
        $template = new Template(
            new Configuration(),
            onFailure: static function(string $expression, Throwable $error) use (&$seen): string {
                $seen[] = $expression;

                return 'fallback';
            },
        );

        static::assertSame('fallback', $template->render('{{ 1 + "a" }}', []));
        static::assertSame(['1 + "a"'], $seen);
    }

    public function testHostSurfacesTheFailureByThrowing(): void
    {
        $template = new Template(
            new Configuration(),
            onFailure: static function(string $expression, Throwable $error): never {
                throw new RuntimeException('bad binding: ' . $expression);
            },
        );

        static::expectException(RuntimeException::class);
        static::expectExceptionMessage('bad binding');

        $template->render('{{ 1 + "a" }}', []);
    }

    // --- The fragment hook ---

    public function testFragmentReportsEachPieceWithItsMark(): void
    {
        $seen = [];
        $this->template->render(
            'a {{ b }} c {{{ d }}}',
            ['b' => 1, 'd' => 2],
            static function(mixed $value, null|bool $mark) use (&$seen): mixed {
                $seen[] = [$value, $mark];

                return $value;
            },
        );

        static::assertSame([['a ', null], [1, false], [' c ', null], [2, true], ['', null]], $seen);
    }

    public function testFragmentLetsAHostRenderAValueItsOwnWay(): void
    {
        $rendered = $this->template->render(
            'paid: {{ paid }}',
            ['paid' => true],
            static fn(mixed $value, null|bool $mark): mixed => null === $mark ? $value : ($value ? '1' : ''),
        );

        static::assertSame('paid: 1', $rendered);
    }
}
