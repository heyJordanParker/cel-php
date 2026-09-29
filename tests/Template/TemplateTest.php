<?php

declare(strict_types=1);

namespace Cel\Tests\Template;

use Cel\Exception\ExceptionInterface;
use Cel\Runtime\Configuration;
use Cel\Template\Template;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- Rendering a whole expression ---

    public function testWholeExpressionKeepsItsValueType(): void
    {
        static::assertSame(12, $this->template->render('{{ order.total }}', ['order' => ['total' => 12]]));
    }

    public function testWholeExecutableExpressionKeepsItsValueType(): void
    {
        static::assertSame(12, $this->template->render('{{{ order.total }}}', ['order' => ['total' => 12]]));
    }

    public function testWholeExpressionToleratesSurroundingWhitespace(): void
    {
        static::assertSame(3, $this->template->render('  {{ total }}  ', ['total' => 3]));
    }

    public function testValueWithoutAnExpressionRendersUnchanged(): void
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

    // --- Rendering expressions inside text ---

    public function testExpressionSurroundedByTextInterpolates(): void
    {
        static::assertSame('Hi Ada!', $this->template->render('Hi {{ name }}!', ['name' => 'Ada']));
    }

    public function testSeveralExpressionsInterpolate(): void
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

    public function testFailingExpressionRendersAsNothing(): void
    {
        static::assertSame('[]', $this->template->render('[{{ 1 + "a" }}]', []));
    }

    // --- The escape ---

    public function testEscapedOpenerRendersAsALiteral(): void
    {
        static::assertSame(
            '{{ not an expression }}',
            $this->template->render('\\{{ not an expression }}', []),
        );
    }

    public function testEscapeMarksEveryOpener(): void
    {
        static::assertSame('a \\{{ b }} c', Template::escape('a {{ b }} c'));
    }

    public function testEscapedOpenerRendersBesideARealExpression(): void
    {
        static::assertSame('a {{ b }} 1', $this->template->render('a \\{{ b }} {{ c }}', ['c' => 1]));
    }

    public function testEscapedTextRendersBackToWhatTheAuthorWrote(): void
    {
        $original = 'if (x) {{ y }}';

        static::assertSame($original, $this->template->render(Template::escape($original), []));
    }

    // --- Validity ---

    public function testValidExpressionIsValid(): void
    {
        static::assertTrue(Template::isValid('{{ order.total > 0 }}'));
    }

    public function testValueWithoutAnExpressionIsValid(): void
    {
        static::assertTrue(Template::isValid('plain'));
    }

    public function testExpressionThatDoesNotParseIsInvalid(): void
    {
        static::assertFalse(Template::isValid('{{ order. }}'));
    }

    public function testEmptyExpressionIsInvalid(): void
    {
        static::assertFalse(Template::isValid('{{}}'));
    }

    public function testOpenerWithoutACloserIsInvalid(): void
    {
        static::assertFalse(Template::isValid('{{ order.total'));
    }

    public function testEscapedOpenerWithoutACloserIsValid(): void
    {
        static::assertTrue(Template::isValid('\\{{ order.total'));
    }

    public function testValidityWalksAnArrayOfValues(): void
    {
        static::assertFalse(Template::isValid(['{{ a }}', '{{ b. }}']));
    }

    // --- Roots ---

    public function testRootsNamesTheVariableAnExpressionReads(): void
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

    public function testRootsNamesEveryVariableAcrossExpressions(): void
    {
        static::assertSame(['a', 'b'], $this->template->roots('{{ a }} x {{ b.c }}'));
    }

    public function testRootsWalksAnArrayOfValues(): void
    {
        static::assertSame(['a', 'b'], $this->template->roots(['{{ a }}', '{{ b }}']));
    }

    public function testRootsNamesTheReceiverOfAComprehensionAndNotItsVariable(): void
    {
        static::assertSame(['article'], $this->template->roots('{{ article.links.map(l, l.title) }}'));
    }

    public function testRootsNamesNothingForAnExpressionThatDoesNotParse(): void
    {
        static::assertSame([], $this->template->roots('{{ a. }}'));
    }

    public function testRootsNamesNothingInsideAStringLiteral(): void
    {
        static::assertSame([], $this->template->roots('{{ "order.total" }}'));
    }

    // --- Paths ---

    public function testPathsNamesTheFieldsAnExpressionReads(): void
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
            ['offers' => [[]], 'position' => [[]]],
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

    public function testPathsStopsAtTheCollectionAComprehensionWalks(): void
    {
        static::assertSame(
            ['article' => [['links']]],
            $this->template->paths('{{ article.links.map(l, l.title) }}'),
        );
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

    public function testPathsNamesNothingForAnExpressionThatDoesNotParse(): void
    {
        static::assertSame([], $this->template->paths('{{ a. }}'));
    }

    public function testPathsNamesNothingInsideAStringLiteral(): void
    {
        static::assertSame([], $this->template->paths('{{ "order.total" }}'));
    }

    // --- References ---

    public function testReferencesNamesEachPathTheCodeReads(): void
    {
        static::assertSame(
            [['order', 'total'], ['contact', 'email']],
            Template::references('order.total > 0 && contact.email != ""'),
        );
    }

    public function testReferencesGivesABareRootItsOwnPath(): void
    {
        static::assertSame([['order']], Template::references('order'));
    }

    public function testReferencesNamesEachPathOnce(): void
    {
        static::assertSame([['a', 'b']], Template::references('a.b + a.b'));
    }

    public function testReferencesReadsALiteralIndexAsItsOwnSegment(): void
    {
        static::assertSame([['offers', 1, 'price']], Template::references('offers[1].price'));
        static::assertSame([['labels', 'en', 'title']], Template::references('labels["en"].title'));
    }

    public function testReferencesReadsAComputedIndexAsEveryItem(): void
    {
        static::assertSame([['items', null, 'price'], ['i']], Template::references('items[i].price'));
    }

    public function testReferencesReadsThroughParentheses(): void
    {
        static::assertSame([['order', 'total']], Template::references('(order).total'));
    }

    public function testReferencesDoesNotNameAFunctionOrAString(): void
    {
        static::assertSame(
            [['cart', 'items']],
            Template::references('size(cart.items) > 0 && "order.total" != ""'),
        );
    }

    public function testReferencesReadsTheArgumentOfHas(): void
    {
        static::assertSame([['contact', 'email']], Template::references('has(contact.email)'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function singleVariableComprehensions(): iterable
    {
        yield 'map' => ['offers.map(o, o.active)'];
        yield 'filter' => ['offers.filter(o, o.active)'];
        yield 'all' => ['offers.all(o, o.active)'];
        yield 'exists' => ['offers.exists(o, o.active)'];
        yield 'exists_one' => ['offers.exists_one(o, o.active)'];
    }

    #[DataProvider('singleVariableComprehensions')]
    public function testReferencesReadsAComprehensionVariableThroughItsCollection(string $code): void
    {
        static::assertSame([['offers'], ['offers', null, 'active']], Template::references($code));
    }

    public function testReferencesReadsEveryArgumentOfAFilteringMap(): void
    {
        static::assertSame(
            [['offers'], ['offers', null, 'active'], ['offers', null, 'price']],
            Template::references('offers.map(o, o.active, o.price)'),
        );
    }

    public function testReferencesReadsABareComprehensionVariableAsEveryItem(): void
    {
        static::assertSame([['offers'], ['offers', null]], Template::references('offers.map(o, o)'));
    }

    public function testReferencesReadsNoPathForAnIndexOrKeyVariable(): void
    {
        static::assertSame(
            [['offers'], ['offers', null, 'price']],
            Template::references('offers.all(i, o, o.price > i)'),
        );
        static::assertSame(
            [['offers'], ['offers', null, 'price']],
            Template::references('offers.transformList(i, o, i < 3, o.price)'),
        );
    }

    public function testReferencesReadsAnOptionalsValueAsTheOptional(): void
    {
        static::assertSame(
            [['contact', 'address'], ['contact', 'address', 'city']],
            Template::references('contact.?address.optMap(a, a.city)'),
        );
    }

    public function testReferencesReadsNestedComprehensionsThroughEachCollection(): void
    {
        static::assertSame(
            [
                ['funnel', 'steps'],
                ['funnel', 'steps', null, 'offers'],
                ['funnel', 'steps', null, 'offers', null, 'price'],
            ],
            Template::references('funnel.steps.map(s, s.offers.map(o, o.price))'),
        );
    }

    public function testReferencesLetsAComprehensionVariableShadowARoot(): void
    {
        static::assertSame(
            [['item'], ['item', null, 'name']],
            Template::references('item.map(item, item.name)'),
        );
    }

    public function testReferencesReadsARootInsideAComprehensionBody(): void
    {
        static::assertSame(
            [['offers'], ['offers', null, 'price'], ['cart', 'total']],
            Template::references('offers.filter(o, o.price < cart.total)'),
        );
    }

    public function testReferencesReadsNoPathThroughACollectionThatIsNotOne(): void
    {
        static::assertSame([['a'], ['b']], Template::references('[a, b].map(x, x.y)'));
    }

    public function testReferencesThrowsWhenTheCodeDoesNotParse(): void
    {
        static::expectException(ExceptionInterface::class);

        Template::references('order.');
    }

    // --- Renaming a path ---

    public function testRenameRewritesAPath(): void
    {
        static::assertSame(
            'fields.contact == "x"',
            Template::rename('fields.email == "x"', 'fields.email', 'fields.contact'),
        );
    }

    public function testRenameRewritesEveryRead(): void
    {
        static::assertSame(
            'fields.contact + fields.contact',
            Template::rename('fields.email + fields.email', 'fields.email', 'fields.contact'),
        );
    }

    public function testRenameKeepsTheRestOfALongerPath(): void
    {
        static::assertSame(
            'fields.contact.domain',
            Template::rename('fields.email.domain', 'fields.email', 'fields.contact'),
        );
        static::assertSame(
            'fields.rows[0].name',
            Template::rename('fields.items[0].name', 'fields.items', 'fields.rows'),
        );
    }

    public function testRenameRewritesARoot(): void
    {
        static::assertSame('person.name', Template::rename('contact.name', 'contact', 'person'));
    }

    public function testRenameLeavesAnotherPathAlone(): void
    {
        static::assertSame(
            'fields.emails == "x"',
            Template::rename('fields.emails == "x"', 'fields.email', 'fields.contact'),
        );
        static::assertSame('fields["email"]', Template::rename('fields["email"]', 'fields.email', 'fields.contact'));
    }

    public function testRenameRewritesARootInsideAComprehensionBody(): void
    {
        static::assertSame(
            'items.map(i, fields.contact == i.x)',
            Template::rename('items.map(i, fields.email == i.x)', 'fields.email', 'fields.contact'),
        );
    }

    public function testRenameLeavesAComprehensionVariableThatShadowsTheRoot(): void
    {
        static::assertSame(
            'items.map(fields, fields.email)',
            Template::rename('items.map(fields, fields.email)', 'fields.email', 'fields.contact'),
        );
    }

    public function testRenameLeavesCodeThatDoesNotParse(): void
    {
        static::assertSame('fields.email ==', Template::rename('fields.email ==', 'fields.email', 'fields.contact'));
    }

    // --- Parts ---

    public function testPartsSplitsTextFromExpressions(): void
    {
        static::assertSame(
            ['Hi ', ['code' => 'name', 'raw' => false], '!'],
            Template::parts('Hi {{ name }}!'),
        );
    }

    public function testPartsMarksARawExpression(): void
    {
        static::assertSame([['code' => 'html', 'raw' => true]], Template::parts('{{{ html }}}'));
    }

    public function testPartsTrimsTheCode(): void
    {
        static::assertSame([['code' => 'a', 'raw' => false]], Template::parts('{{   a   }}'));
    }

    public function testPartsReadsAnEscapedOpenerAsText(): void
    {
        static::assertSame(
            ['{{ literal }} ', ['code' => 'a', 'raw' => false]],
            Template::parts('\\{{ literal }} {{ a }}'),
        );
    }

    public function testPartsKeepsAChainAsText(): void
    {
        static::assertSame(['mail @support.team'], Template::parts('mail @support.team'));
    }

    public function testPartsHasNoEmptyText(): void
    {
        static::assertSame(
            [['code' => 'a', 'raw' => false], ['code' => 'b', 'raw' => false]],
            Template::parts('{{ a }}{{ b }}'),
        );
        static::assertSame([], Template::parts(''));
    }

    // --- Composing parts ---

    public function testComposeWritesPartsBackAsTheTemplateTheyCameFrom(): void
    {
        $template = 'Hi {{ name }}, \\{{ not }} {{{ raw }}}';

        static::assertSame($template, Template::compose(Template::parts($template)));
    }

    public function testComposeEscapesTextSoItRendersAsWritten(): void
    {
        static::assertSame('a \\{{ b }}', Template::compose(['a {{ b }}']));
        static::assertSame('a {{ b }}', $this->template->render(Template::compose(['a {{ b }}']), []));
    }

    public function testComposeRefusesCodeThatWouldCloseEarly(): void
    {
        static::expectException(InvalidArgumentException::class);

        Template::compose([['code' => '{"a": {"b": 1}}', 'raw' => false]]);
    }

    public function testComposeRefusesTextThatWouldEscapeTheNextExpression(): void
    {
        static::expectException(InvalidArgumentException::class);

        Template::compose(['a \\', ['code' => 'b', 'raw' => false]]);
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

    public function testExpressionWrapsABody(): void
    {
        static::assertSame('{{ contact.optedIn }}', Template::expression('contact.optedIn'));
    }

    public function testExpressionRefusesABodyThatWouldCloseEarly(): void
    {
        static::expectException(InvalidArgumentException::class);

        Template::expression('a }} b');
    }

    public function testBodyReadsBackOutOfAWholeExpression(): void
    {
        static::assertSame('contact.optedIn', Template::body('{{ contact.optedIn }}'));
    }

    public function testBodyRefusesAValueThatInterpolates(): void
    {
        static::expectException(InvalidArgumentException::class);

        Template::body('x {{ a }} y');
    }

    // --- The failure policy ---

    public function testFailedExpressionResolvesToNullByDefault(): void
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
                throw new RuntimeException('bad expression: ' . $expression);
            },
        );

        static::expectException(RuntimeException::class);
        static::expectExceptionMessage('bad expression');

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
