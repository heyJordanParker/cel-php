<?php

declare(strict_types=1);

namespace Cel\Template;

use Cel\Exception\ExceptionInterface;
use Cel\Parser\Parser;
use Cel\Runtime\Configuration;
use Cel\Syntax\Expression;
use Cel\Syntax\Literal\LiteralExpression;
use Cel\Syntax\Member\CallExpression;
use Cel\Syntax\Member\IdentifierExpression;
use Cel\Syntax\Member\IndexExpression;
use Cel\Syntax\Member\MemberAccessExpression;
use Cel\Syntax\Node;
use Cel\Syntax\ParenthesizedExpression;
use InvalidArgumentException;
use Throwable;

use function Cel\evaluate;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function krsort;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function serialize;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_UNMATCHED_AS_NULL;

/**
 * Expressions written inside ordinary text: `Hello {{ customer.firstName }}`.
 *
 * The grammar is {@see Grammar}. A value that is one whole expression evaluates to
 * that expression's value, keeping its type; an expression surrounded by text renders
 * into the text as a string. Arrays are walked, so a whole document of authored
 * values can be handed in at once.
 *
 * What happens when an expression fails is the caller's policy, not this layer's: a
 * host rendering a page usually wants an unresolvable expression to come out empty
 * rather than to fail the page, while a host validating one wants to hear about
 * it. Pass `$onFailure` to decide; the default resolves a failed expression to null.
 */
final readonly class Template
{
    /**
     * The macros that bind variables, by name and argument count, with the role of
     * each variable they bind: `item` is one element of the target, `key` is an
     * index or map key, and `value` is what an optional holds.
     *
     * @var array<string, array<int, list<'item'|'key'|'value'>>>
     */
    private const array COMPREHENSIONS = [
        'map' => [2 => ['item'], 3 => ['item']],
        'filter' => [2 => ['item']],
        'all' => [2 => ['item'], 3 => ['key', 'item']],
        'exists' => [2 => ['item'], 3 => ['key', 'item']],
        'exists_one' => [2 => ['item']],
        'existsOne' => [3 => ['key', 'item']],
        'transformList' => [3 => ['key', 'item'], 4 => ['key', 'item']],
        'transformMap' => [3 => ['key', 'item'], 4 => ['key', 'item']],
        'optMap' => [2 => ['value']],
        'optFlatMap' => [2 => ['value']],
    ];

    /**
     * @param Configuration $configuration The runtime expressions evaluate against.
     * @param bool $enableChains Whether `@root.field` also opens an expression. Off by default:
     *                           `{{ }}` is the template form, while the bare chain is an
     *                           authoring affordance a host opts into.
     * @param null|(callable(string, Throwable): mixed) $onFailure Given the expression and the
     *                                                            failure, returns the value to use.
     *                                                            Throwing from here surfaces the failure.
     */
    public function __construct(
        private Configuration $configuration,
        private bool $enableChains = false,
        private mixed $onFailure = null,
    ) {}

    /**
     * Wraps a stored expression body in its braces.
     *
     * A body is stored bare, because the surface an author types it into asks
     * for a rule rather than for an expression, so every evaluation site has to put
     * it back. A body carrying `}}` would close the expression early and evaluate
     * something other than what its author wrote.
     */
    public static function expression(string $body): string
    {
        if (str_contains($body, '}}')) {
            throw new InvalidArgumentException('An expression body cannot contain `}}`: ' . $body);
        }

        return '{{ ' . $body . ' }}';
    }

    /**
     * The bare body of a value that is one whole expression.
     *
     * A value that interpolates has no single body to store, so it is refused.
     */
    public static function body(string $value): string
    {
        if (preg_match(Grammar::WHOLE_INERT, $value, $matches) !== 1) {
            throw new InvalidArgumentException('Only a whole expression has an expression body.');
        }

        return $matches[1];
    }

    /** Makes every opening `{{` literal while preserving the rendered text. */
    public static function escape(string $value): string
    {
        return str_replace('{{', Grammar::ESCAPED_OPEN, $value);
    }

    /**
     * The text and the expressions a template is written as, in order.
     *
     * Text comes back as it renders: `\{{` is a literal `{{`, and a `@` chain is
     * text, because the chain opener is a host's to turn on. Each `{{ }}` and
     * `{{{ }}}` comes back as its code and whether its author marked it raw.
     * Adjacent text is one part, and no part is empty.
     *
     * @return list<string|array{code: string, raw: bool}>
     */
    public static function parts(string $template): array
    {
        $matches = [];
        preg_match_all(
            Grammar::SPANS,
            $template,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );
        // @mago-expect analysis:docblock-type-mismatch - the offset-capture shape of the matches is not modelled.
        /** @var list<array{0: array{0: string, 1: int}, 1: array{0: null|string, 1: int}, 2: array{0: null|string, 1: int}}> $matches */

        $parts = [];
        $text = '';
        $offset = 0;

        foreach ($matches as $match) {
            $start = $match[0][1];
            $text .= substr($template, $offset, $start - $offset);
            $offset = $start + strlen($match[0][0]);

            $code = $match[1][0] ?? $match[2][0];
            if (null === $code) {
                $text .= $match[0][0] === Grammar::ESCAPED_OPEN ? '{{' : $match[0][0];

                continue;
            }

            if ('' !== $text) {
                $parts[] = $text;
                $text = '';
            }

            $parts[] = ['code' => $code, 'raw' => null !== $match[1][0]];
        }

        $text .= substr($template, $offset);
        if ('' !== $text) {
            $parts[] = $text;
        }

        return $parts;
    }

    /**
     * Writes parts back as a template, the inverse of {@see parts()}.
     *
     * Text has every `{{` escaped, so it renders as the text it was. Code holding
     * `}}` is refused, as {@see expression()} refuses it, and so is text ending in
     * a backslash before an expression, which would escape that expression.
     *
     * @param list<string|array{code: string, raw: bool}> $parts
     *
     * @throws InvalidArgumentException When a part cannot be written back as itself.
     */
    public static function compose(array $parts): string
    {
        $template = '';

        foreach ($parts as $part) {
            if (is_string($part)) {
                $template .= self::escape($part);

                continue;
            }

            if (str_ends_with($template, '\\')) {
                throw new InvalidArgumentException(
                    'Text ending in `\\` would escape the expression after it: ' . $part['code'],
                );
            }

            $expression = self::expression($part['code']);
            $template .= $part['raw'] ? '{' . $expression . '}' : $expression;
        }

        return $template;
    }

    /**
     * Every path a piece of code reads, as `[root, ...segments]`, in the order
     * the code reads them and each once.
     *
     * A field is a segment and a literal index is a segment of its own. A segment
     * that cannot be named is `null`, meaning every item: a computed index, and
     * the element a comprehension walks. `offers.map(o, o.price)` reads
     * `[offers]` and `[offers, null, price]`, and `items[i].price` reads
     * `[items, null, price]` and `[i]`. A comprehension's variables are never
     * roots: an element reads through its collection, and an index or key reads
     * no path. A function name and a string's contents are never paths.
     *
     * @return list<non-empty-list<string|int|null>>
     *
     * @throws ExceptionInterface When the code does not parse.
     */
    public static function references(string $code): array
    {
        $reads = [];
        self::walk(Parser::default()->parseString($code), [], $reads);

        $references = [];
        foreach ($reads as $read) {
            if (null === $read['path']) {
                continue;
            }

            $references[serialize($read['path'])] = $read['path'];
        }

        return array_values($references);
    }

    /**
     * Rewrites every read of the dotted path `$from` in the code to `$to`.
     *
     * A longer path that starts with it is rewritten too, keeping the rest:
     * renaming `fields.email` turns `fields.email.domain` into
     * `fields.contact.domain`. A comprehension variable that shadows the root is
     * left alone, and code that does not parse comes back as it was.
     */
    public static function rename(string $code, string $from, string $to): string
    {
        try {
            $root = Parser::default()->parseString($code);
        } catch (ExceptionInterface) {
            return $code;
        }

        $reads = [];
        self::walk($root, [], $reads);

        $path = explode('.', $from);
        $depth = count($path) - 1;
        $spans = [];

        foreach ($reads as $read) {
            $node = $read['nodes'][$depth] ?? null;
            if (!$read['free'] || null === $node) {
                continue;
            }

            foreach ($path as $position => $segment) {
                if (
                    ($read['nodes'][$position] ?? null) instanceof IndexExpression
                    || ($read['written'][$position] ?? null) !== $segment
                ) {
                    continue 2;
                }
            }

            $span = $node->getSpan();
            $spans[$span->start] = $span;
        }

        krsort($spans);
        foreach ($spans as $span) {
            $code = substr($code, 0, $span->start) . $to . substr($code, $span->start + $span->length());
        }

        return $code;
    }

    /**
     * The variable names the expressions in a value read — `order` for
     * `{{ order.total }}`, `cart` for `{{ money(cart.total) }}`. Arrays are
     * walked, so a whole document can be handed in.
     *
     * A caller needs these to know which variables a document reaches, so it
     * can supply exactly those and no more. They are the roots of
     * {@see references()}, so a comprehension's variable is never one. An
     * expression that does not parse contributes nothing, because an expression
     * that cannot run reads no variable.
     *
     * @return list<string>
     */
    public function roots(mixed $value): array
    {
        $roots = [];
        foreach ($this->referencesIn($value) as $reference) {
            $roots[(string) $reference[0]] = true;
        }

        return array_keys($roots);
    }

    /**
     * The member chains the expressions in a value read, grouped by their root
     * variable — `['order' => [['total']]]` for `{{ order.total }}`.
     *
     * `roots()` answers which variables a value reaches. This answers how far
     * into each one it reaches, which is what a caller needs to read exactly the
     * fields a document names instead of whole objects. A root read bare
     * (`{{ order }}`) contributes the empty chain, which means the whole value.
     *
     * A chain is a path from {@see references()} cut where it meets a segment
     * that cannot be named, so it stops at the collection and the caller reads
     * across it: `links.map(l, l.title)` and `links[i].title` both yield `links`.
     *
     * @return array<string, list<list<string|int>>>
     */
    public function paths(mixed $value): array
    {
        $paths = [];
        foreach ($this->referencesIn($value) as $reference) {
            $chain = [];
            foreach (array_slice($reference, 1) as $segment) {
                if (null === $segment) {
                    break;
                }

                $chain[] = $segment;
            }

            $paths[(string) $reference[0]][serialize($chain)] = $chain;
        }

        $grouped = [];
        foreach ($paths as $root => $chains) {
            $grouped[$root] = array_values($chains);
        }

        return $grouped;
    }

    /**
     * Every path every expression in a value reads. An expression that does not
     * parse reads nothing.
     *
     * @return list<non-empty-list<string|int|null>>
     */
    private function referencesIn(mixed $value): array
    {
        if (is_array($value)) {
            $references = [];
            foreach ($value as $item) {
                foreach ($this->referencesIn($item) as $reference) {
                    $references[serialize($reference)] = $reference;
                }
            }

            return array_values($references);
        }

        if (!is_string($value)) {
            return [];
        }

        $expressions = [];
        foreach (self::parts($value) as $part) {
            if (is_array($part)) {
                $expressions[] = $part['code'];
            } elseif ($this->enableChains) {
                $chains = [];
                preg_match_all('/' . Grammar::AT_CHAIN . '/', $part, $chains);
                foreach ($chains[1] ?? [] as $chain) {
                    $expressions[] = $chain;
                }
            }
        }

        $references = [];
        foreach ($expressions as $expression) {
            try {
                $read = self::references($expression);
            } catch (ExceptionInterface) {
                continue;
            }

            foreach ($read as $reference) {
                $references[serialize($reference)] = $reference;
            }
        }

        return array_values($references);
    }

    /**
     * Collects every chain the tree reads: the path it resolves to (null when it
     * reads no path), whether its root is a free variable, the segments as
     * written, and the node that ends each written segment.
     *
     * Answers the collection the node's value holds items of, when that is a path:
     * a `filter` keeps items of the collection it walks. Any other call builds new
     * values, which no path names.
     *
     * @param array<string, null|non-empty-list<string|int|null>> $scope What each bound variable resolves to.
     * @param list<array{path: null|non-empty-list<string|int|null>, free: bool, written: non-empty-list<string|int|null>, nodes: non-empty-list<Expression>}> $reads
     *
     * @return null|non-empty-list<string|int|null>
     */
    private static function walk(Node $node, array $scope, array &$reads): null|array
    {
        if (
            $node instanceof IdentifierExpression
            || $node instanceof MemberAccessExpression
            || $node instanceof IndexExpression
        ) {
            self::walkChain($node, $scope, $reads);

            return null;
        }

        $target = $node instanceof CallExpression ? $node->target : null;
        $arguments = $node instanceof CallExpression ? $node->arguments->elements : [];
        $roles = $node instanceof CallExpression && null !== $target
            ? self::COMPREHENSIONS[$node->function->name][count($arguments)] ?? []
            : [];
        $variables = array_slice($arguments, 0, count($roles));

        if (
            null === $target
            || [] === $roles
            || [] !== array_filter(
                $variables,
                static fn(Expression $variable): bool => !$variable instanceof IdentifierExpression,
            )
        ) {
            foreach ($node->getChildren() as $child) {
                self::walk($child, $scope, $reads);
            }

            return null;
        }

        $collection = self::walkChain($target, $scope, $reads);

        foreach ($variables as $position => $variable) {
            if (!$variable instanceof IdentifierExpression) {
                continue;
            }

            $scope[$variable->identifier->name] = match ($roles[$position] ?? 'key') {
                'item' => null === $collection ? null : [...$collection, null],
                'key' => null,
                'value' => $collection,
            };
        }

        foreach (array_slice($arguments, count($roles)) as $argument) {
            self::walk($argument, $scope, $reads);
        }

        return $node instanceof CallExpression && 'filter' === $node->function->name ? $collection : null;
    }

    /**
     * Walks one chain and answers the path it resolves to, or null when it reads
     * no path.
     *
     * @param array<string, null|non-empty-list<string|int|null>> $scope
     * @param list<array{path: null|non-empty-list<string|int|null>, free: bool, written: non-empty-list<string|int|null>, nodes: non-empty-list<Expression>}> $reads
     *
     * @return null|non-empty-list<string|int|null>
     */
    private static function walkChain(Expression $node, array $scope, array &$reads): null|array
    {
        /** @var list<string|int|null> $segments */
        $segments = [];
        /** @var list<Expression> $nodes */
        $nodes = [];
        /** @var list<Expression> $indexes */
        $indexes = [];
        $current = $node;

        while (
            $current instanceof MemberAccessExpression
            || $current instanceof IndexExpression
            || $current instanceof ParenthesizedExpression
        ) {
            if ($current instanceof ParenthesizedExpression) {
                $current = $current->expression;

                continue;
            }

            if ($current instanceof MemberAccessExpression) {
                $segments = [$current->field->name, ...$segments];
            } else {
                $literal = $current->index instanceof LiteralExpression ? $current->index->getValue() : null;
                $segments = [is_int($literal) || is_string($literal) ? $literal : null, ...$segments];
                $indexes[] = $current->index;
            }

            $nodes = [$current, ...$nodes];
            $current = $current->operand;
        }

        $path = null;
        if ($current instanceof IdentifierExpression) {
            $name = $current->identifier->name;
            $free = !array_key_exists($name, $scope);
            $prefix = $free ? [$name] : $scope[$name] ?? null;
            $path = null === $prefix ? null : [...$prefix, ...$segments];

            $reads[] = [
                'path' => $path,
                'free' => $free,
                'written' => [$name, ...$segments],
                'nodes' => [$current, ...$nodes],
            ];
        } else {
            $filtered = self::walk($current, $scope, $reads);
            $path = [] === $segments ? $filtered : null;
        }

        foreach (array_reverse($indexes) as $index) {
            self::walk($index, $scope, $reads);
        }

        return $path;
    }

    /**
     * Whether the value holds an expression at all.
     *
     * When `$values` is given, an `@` chain counts only where its root is one
     * of them, because an unresolvable chain stays literal text.
     *
     * @param null|array<string, mixed> $values
     */
    public function containsExpression(string $value, null|array $values = null): bool
    {
        if (preg_match('/(?<!\\\\)\{\{/', $value) === 1) {
            return true;
        }

        if (!$this->enableChains) {
            return false;
        }

        if (preg_match_all('/' . Grammar::AT_CHAIN . '/', $value, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $chain) {
            if (null === $values || self::isBoundRoot($chain, $values)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the value holds a `{{{ }}}` expression. */
    public static function containsExecutableExpression(string $value): bool
    {
        return preg_match('/(?<!\\\\)\{\{\{/', $value) === 1;
    }

    /**
     * Whether the value holds a `{{ }}` expression once every `{{{ }}}` span is
     * removed.
     *
     * @param null|array<string, mixed> $values
     */
    public function containsInertExpression(string $value, null|array $values = null): bool
    {
        $withoutExecutable = preg_replace('/(?<!\\\\)\{\{\{\s*(.*?)\s*\}\}\}/s', '', $value);

        return $this->containsExpression(is_string($withoutExecutable) ? $withoutExecutable : $value, $values);
    }

    /**
     * Whether the value is one expression and nothing else, so its evaluated value
     * keeps its type instead of rendering into text.
     *
     * @param array<string, mixed> $values
     */
    public function isWholeExpression(string $value, array $values): bool
    {
        return preg_match(Grammar::WHOLE_EXECUTABLE, $value) === 1
            || preg_match(Grammar::WHOLE_INERT, $value) === 1
            || $this->isWholeChain($value, $values);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function isWholeChain(string $value, array $values): bool
    {
        return $this->enableChains
            && preg_match('/^\s*' . Grammar::AT_CHAIN . '\s*$/', $value, $match) === 1
            && self::isBoundRoot($match[1], $values);
    }

    /** Whether every expression in the value parses. */
    public static function isValid(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if (!self::isValid($child)) {
                    return false;
                }
            }

            return true;
        }

        if (!is_string($value) || !str_contains($value, '{{')) {
            return true;
        }

        // An escaped `\{{` is a literal, so it neither opens an expression nor
        // counts as a stray unescaped one.
        $unescaped = preg_replace('/' . preg_quote(Grammar::ESCAPED_OPEN, '/') . '/', '', $value);
        $unescaped = is_string($unescaped) ? $unescaped : $value;

        preg_match_all(
            '/\{\{\{\s*(.*?)\s*\}\}\}|\{\{\s*(.*?)\s*\}\}/s',
            $unescaped,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        $withoutExpressions = $unescaped;
        foreach ($matches as $match) {
            $withoutExpressions = str_replace($match[0], '', $withoutExpressions);
        }

        // An opener with no closer is not an expression and never will be.
        if (str_contains($withoutExpressions, '{{')) {
            return false;
        }

        foreach ($matches as $match) {
            $expression = trim((string) ($match[1] ?? $match[2]));
            if ('' === $expression || !self::parses($expression)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renders every expression in the value against the variables.
     *
     * A value that is one whole expression returns that expression's evaluated value
     * with its type intact. Anything else renders to a string. Arrays are
     * walked.
     *
     * `$fragment` is called for each piece of a rendered string — literal text
     * with null, an evaluated expression with whether its author marked it
     * executable — so a host can treat the two differently on output. Without
     * it the pieces are concatenated.
     *
     * @param array<string, mixed> $values
     * @param null|(callable(mixed, null|bool): mixed) $fragment
     */
    public function render(mixed $value, array $values, null|callable $fragment = null): mixed
    {
        if (is_array($value)) {
            return array_map(
                fn(mixed $item): mixed => $this->render($item, $values, $fragment),
                $value,
            );
        }

        $opens = is_string($value)
            && (str_contains($value, '{{') || ($this->enableChains && str_contains($value, '@')));

        if (!$opens) {
            return null === $fragment ? $value : $fragment($value, null);
        }

        $parts = self::parts($value);
        $expressions = array_values(array_filter($parts, is_array(...)));
        $whole = 1 === count($expressions) && '' === trim(implode('', array_filter($parts, is_string(...))))
            ? $expressions[0]
            : null;

        if (null !== $whole) {
            $result = $this->evaluateExpression($whole['code'], $values);

            return null === $fragment ? $result : $fragment($result, $whole['raw']);
        }

        if ($this->isWholeChain($value, $values)) {
            preg_match('/^\s*' . Grammar::AT_CHAIN . '\s*$/', $value, $match);
            $result = $this->evaluateExpression($match[1], $values);

            return null === $fragment ? $result : $fragment($result, false);
        }

        $rendered = '';
        $text = '';

        foreach ($parts as $part) {
            if (is_string($part)) {
                $text = $part;

                continue;
            }

            $rendered .= $this->renderText($text, $values, $fragment);
            $text = '';

            $result = $this->evaluateExpression($part['code'], $values);
            $rendered .= (string) (null === $fragment ? self::text($result) : $fragment($result, $part['raw']));
        }

        return $rendered . $this->renderText($text, $values, $fragment);
    }

    /**
     * Renders text, resolving each chain a variable can resolve when the host
     * turned chains on. Any other chain stays the text the author wrote.
     *
     * @param array<string, mixed> $values
     * @param null|(callable(mixed, null|bool): mixed) $fragment
     */
    private function renderText(string $text, array $values, null|callable $fragment): string
    {
        $pieces = $this->enableChains
            ? preg_split('/' . Grammar::AT_CHAIN . '/', $text, -1, PREG_SPLIT_DELIM_CAPTURE)
            : false;

        $rendered = '';
        $literal = '';

        foreach (false === $pieces ? [$text] : $pieces as $position => $piece) {
            if (0 === $position % 2) {
                $literal .= $piece;

                continue;
            }

            if (!self::isBoundRoot($piece, $values)) {
                $literal .= '@' . $piece;

                continue;
            }

            $rendered .= (string) (null === $fragment ? $literal : $fragment($literal, null));
            $literal = '';

            $result = $this->evaluateExpression($piece, $values);
            $rendered .= (string) (null === $fragment ? self::text($result) : $fragment($result, false));
        }

        return $rendered . (string) (null === $fragment ? $literal : $fragment($literal, null));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function evaluateExpression(string $expression, array $values): mixed
    {
        $expression = trim($expression);

        try {
            return evaluate($expression, $values, $this->configuration)->getRawValue();
        } catch (Throwable $exception) {
            if (null === $this->onFailure) {
                return null;
            }

            return ($this->onFailure)($expression, $exception);
        }
    }

    private static function parses(string $expression): bool
    {
        try {
            Parser::default()->parseString($expression);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function isBoundRoot(string $chain, array $values): bool
    {
        $root = explode('.', $chain, 2)[0];
        $root = explode('[', $root, 2)[0];

        return array_key_exists($root, $values);
    }

    /**
     * An expression's value as text, matching what the `string()` conversion of the
     * same value produces. A host wanting its own rendering — a currency, a
     * date, a boolean written some other way — passes `$fragment` to `render`
     * and receives the value before it becomes text.
     */
    private static function text(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
