<?php

declare(strict_types=1);

namespace Cel\Template;

use Cel\Parser\Parser;
use Cel\Runtime\Configuration;
use Cel\Syntax\Literal\LiteralExpression;
use Cel\Syntax\Member\IdentifierExpression;
use Cel\Syntax\Member\IndexExpression;
use Cel\Syntax\Member\MemberAccessExpression;
use Cel\Syntax\Node;
use InvalidArgumentException;
use Throwable;

use function Cel\evaluate;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unshift;
use function array_values;
use function explode;
use function implode;
use function is_int;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strlen;
use function substr;
use function trim;

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
     * The variable names the expressions in a value read — `order` for
     * `{{ order.total }}`, `cart` for `{{ money(cart.total) }}`. Arrays are
     * walked, so a whole document can be handed in.
     *
     * A caller needs these to know which variables a document reaches, so it
     * can supply exactly those and no more. The names come from each expression's
     * parse tree: an identifier used as a value is a variable, while a field
     * name and a function name are not identifier expressions at all and never
     * appear. An expression that does not parse contributes nothing, because an
     * expression that cannot run reads no variable.
     *
     * A comprehension's loop variable is a name like any other here. It resolves
     * to nothing when supplied, so naming it costs nothing, where missing a real
     * variable would blank the expression.
     *
     * @return list<string>
     */
    public function roots(mixed $value): array
    {
        if (is_array($value)) {
            $roots = [];
            foreach ($value as $item) {
                foreach ($this->roots($item) as $root) {
                    $roots[$root] = true;
                }
            }

            return array_keys($roots);
        }

        if (!is_string($value)) {
            return [];
        }

        $roots = [];
        foreach ($this->expressions($value) as $expression) {
            foreach (self::identifiers($expression) as $identifier) {
                $roots[$identifier] = true;
            }
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
     * A literal index is a segment of its own, so `offers[1].price` names one
     * item. Anything else — a computed index, a map key held in a variable —
     * cannot be named, so the chain stops at the collection and the caller reads
     * across it. A method call is not a field: `links.map(l, l.title)` yields
     * `links`, and the lambda body's `l` is a root like any other, resolving to
     * nothing when it is supplied.
     *
     * @return array<string, list<list<string|int>>>
     */
    public function paths(mixed $value): array
    {
        if (is_array($value)) {
            $paths = [];
            foreach ($value as $item) {
                foreach ($this->paths($item) as $root => $chains) {
                    foreach ($chains as $chain) {
                        $paths[$root][] = $chain;
                    }
                }
            }

            return array_map(self::unique(...), $paths);
        }

        if (!is_string($value)) {
            return [];
        }

        $paths = [];
        foreach ($this->expressions($value) as $expression) {
            foreach (self::chains($expression) as [$root, $chain]) {
                $paths[$root][] = $chain;
            }
        }

        return array_map(self::unique(...), $paths);
    }

    /**
     * @param list<list<string|int>> $chains
     *
     * @return list<list<string|int>>
     */
    private static function unique(array $chains): array
    {
        $seen = [];
        foreach ($chains as $chain) {
            $seen[implode("\0", array_map(strval(...), $chain))] = $chain;
        }

        return array_values($seen);
    }

    /**
     * Every rooted chain in an expression, as `[root, segments]` pairs.
     *
     * @return list<array{0: string, 1: list<string|int>}>
     */
    private static function chains(string $expression): array
    {
        if ('' === $expression) {
            return [];
        }

        try {
            $node = Parser::default()->parseString($expression);
        } catch (Throwable) {
            return [];
        }

        $chains = [];
        self::collect($node, $chains);

        return $chains;
    }

    /**
     * @param list<array{0: string, 1: list<string|int>}> $chains
     */
    private static function collect(Node $node, array &$chains): void
    {
        if ($node instanceof MemberAccessExpression || $node instanceof IndexExpression) {
            $segments = [];
            $current = $node;

            while (true) {
                if ($current instanceof MemberAccessExpression) {
                    array_unshift($segments, $current->field->name);
                    $current = $current->operand;

                    continue;
                }

                if ($current instanceof IndexExpression) {
                    // The index is an expression of its own and may read
                    // variables: `items[position.current]` reads `position` too.
                    self::collect($current->index, $chains);

                    $literal = $current->index instanceof LiteralExpression
                        ? $current->index->getValue()
                        : null;

                    if (is_int($literal) || is_string($literal)) {
                        array_unshift($segments, $literal);
                    } else {
                        // Nothing past an unnameable index belongs to a path: the
                        // chain is the collection, and every item is read.
                        $segments = [];
                    }

                    $current = $current->operand;

                    continue;
                }

                break;
            }

            if ($current instanceof IdentifierExpression) {
                $chains[] = [$current->identifier->name, $segments];

                return;
            }

            // The chain bottoms out on something that is not a variable — a call
            // or a literal — so it names no path, but its own tree may.
            self::collect($current, $chains);

            return;
        }

        if ($node instanceof IdentifierExpression) {
            $chains[] = [$node->identifier->name, []];

            return;
        }

        foreach ($node->getChildren() as $child) {
            self::collect($child, $chains);
        }
    }

    /**
     * Every expression written in the value, taken from the braces around
     * them. A chain contributes its own text, which is an expression already.
     *
     * @return list<string>
     */
    private function expressions(string $value): array
    {
        $unescaped = str_replace(Grammar::ESCAPED_OPEN, '', $value);

        $expressions = [];

        preg_match_all(Grammar::EXPRESSIONS, $unescaped, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $expressions[] = trim('' !== $match[1] ? $match[1] : ($match[2] ?? ''));
        }

        if ($this->enableChains) {
            preg_match_all('/' . Grammar::AT_CHAIN . '/', $unescaped, $chains);
            foreach ($chains[1] ?? [] as $chain) {
                $expressions[] = $chain;
            }
        }

        return $expressions;
    }

    /**
     * The identifiers an expression reads, from its parse tree.
     *
     * @return list<string>
     */
    private static function identifiers(string $expression): array
    {
        if ('' === $expression) {
            return [];
        }

        try {
            $node = Parser::default()->parseString($expression);
        } catch (Throwable) {
            return [];
        }

        $identifiers = [];
        self::visit($node, static function(Node $node) use (&$identifiers): void {
            if ($node instanceof IdentifierExpression) {
                $identifiers[] = $node->identifier->name;
            }
        });

        return $identifiers;
    }

    /**
     * @param callable(Node): void $visitor
     */
    private static function visit(Node $node, callable $visitor): void
    {
        $visitor($node);
        foreach ($node->getChildren() as $child) {
            self::visit($child, $visitor);
        }
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
    public function isValid(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if (!$this->isValid($child)) {
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

        if (preg_match(Grammar::WHOLE_EXECUTABLE, $value, $match) === 1) {
            $result = $this->evaluateExpression($match[1], $values);

            return null === $fragment ? $result : $fragment($result, true);
        }

        if (preg_match(Grammar::WHOLE_INERT, $value, $match) === 1) {
            $result = $this->evaluateExpression($match[1], $values);

            return null === $fragment ? $result : $fragment($result, false);
        }

        if ($this->isWholeChain($value, $values)) {
            preg_match('/^\s*' . Grammar::AT_CHAIN . '\s*$/', $value, $match);
            $result = $this->evaluateExpression($match[1], $values);

            return null === $fragment ? $result : $fragment($result, false);
        }

        return $this->renderText($value, $values, $fragment);
    }

    /**
     * @param array<string, mixed> $values
     * @param null|(callable(mixed, null|bool): mixed) $fragment
     */
    private function renderText(string $value, array $values, null|callable $fragment): string
    {
        preg_match_all(
            Grammar::SPANS,
            $value,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        $rendered = '';
        $offset = 0;

        foreach ($matches as $match) {
            $start = $match[0][1];
            $literal = substr($value, $offset, $start - $offset);
            $rendered .= (string) (null === $fragment ? $literal : $fragment($literal, null));

            if ($match[0][0][0] === '\\') {
                $rendered .= (string) (null === $fragment ? '{{' : $fragment('{{', null));
            } elseif ($match[1][1] !== -1) {
                $result = $this->evaluateExpression($match[1][0], $values);
                $rendered .= (string) (
                    null === $fragment ? self::text($result) : $fragment($result, true)
                );
            } elseif ($match[2][1] !== -1) {
                $result = $this->evaluateExpression($match[2][0], $values);
                $rendered .= (string) (
                    null === $fragment ? self::text($result) : $fragment($result, false)
                );
            } elseif (!$this->enableChains || !self::isBoundRoot($match[3][0], $values)) {
                // A chain the host did not turn on, or one no variable can
                // resolve, stays the text the author wrote.
                $rendered .= (string) (null === $fragment ? $match[0][0] : $fragment($match[0][0], null));
            } else {
                $result = $this->evaluateExpression($match[3][0], $values);
                $rendered .= (string) (
                    null === $fragment ? self::text($result) : $fragment($result, false)
                );
            }

            $offset = $start + strlen($match[0][0]);
        }

        $literal = substr($value, $offset);

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
