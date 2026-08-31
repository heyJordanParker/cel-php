<?php

declare(strict_types=1);

namespace Cel\Template;

/**
 * The shape of an expression written inside ordinary text.
 *
 * Two openers reach the same expression language:
 *
 * - `{{ expression }}` — a bracketed binding the author closes. `{{{ }}}` is
 *   the same binding, marked by its author as carrying intent the host treats
 *   differently on output; the expression inside is identical either way and
 *   the language never sees the difference.
 * - `@root.field[0]` — a bare chain, ending by grammar at the first character
 *   outside `root(.field|[n])*` shape. It opens only at a word boundary, so an
 *   email address never starts one.
 *
 * A backslash before an opening `{{` is the one escape: `\{{` renders a literal
 * `{{`, which lets an author write a bare moustache without opening a binding.
 *
 * These patterns are the contract every implementation of this template layer
 * matches, so text authored once resolves the same wherever it renders.
 */
final readonly class Grammar
{
    /**
     * A chain of a root identifier plus `.field` and `[n]` steps, introduced by
     * `@` at a word boundary. The lookbehind rejects a preceding letter, digit
     * or underscore, and the step class excludes a trailing dot.
     */
    public const string AT_CHAIN = '(?<![A-Za-z0-9_])@([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])*)';

    /** The escape that makes an opening `{{` literal. */
    public const string ESCAPED_OPEN = '\\{{';

    /**
     * A value that is one inert binding and nothing else, so its evaluated
     * value is the result rather than text interpolated around it. The body may
     * not carry the binding's own closer: without that guard `{{ a }} x {{ b }}`
     * matches as a single binding whose body is not an expression.
     */
    public const string WHOLE_INERT = '/^\s*\{\{\s*((?:(?!\}\}).)*?)\s*\}\}\s*$/s';

    /** The `{{{ }}}` counterpart of {@see WHOLE_INERT}. */
    public const string WHOLE_EXECUTABLE = '/^\s*\{\{\{\s*((?:(?!\}\}\}).)*?)\s*\}\}\}\s*$/s';

    /** Every binding of either form, for scanning bodies. */
    public const string BINDINGS = '/(?<!\\\\)(?:\{\{\{\s*(.*?)\s*\}\}\}|\{\{\s*(.*?)\s*\}\})/s';

    /**
     * Every escape and binding in one pass, in document order, so a render
     * walks the text once.
     *
     * A chain is always matched here. Whether a match is a binding or the text
     * an author wrote is the caller's decision, because the chain opener is
     * one a host turns on.
     */
    public const string SPANS = '/\\\\\{\{|\{\{\{\s*(.*?)\s*\}\}\}|\{\{\s*(.*?)\s*\}\}|' . self::AT_CHAIN . '/s';
}
