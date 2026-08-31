<?php

declare(strict_types=1);

namespace Cel\Extension\DateTime;

use DateTimeImmutable;

use function str_contains;
use function strlen;

/**
 * Renders a date through the format characters `date()` understands.
 *
 * The set below is the contract, and it is deliberately narrower than PHP's own
 * format characters. Expressions are authored once and evaluated by more than
 * one implementation of this language, so a character is in the set only where
 * every implementation can produce the same output for it without timezone or
 * locale data of its own. A character outside the set renders as itself, and a
 * backslash renders the character after it literally.
 *
 * Excluded on purpose, all of which PHP would otherwise interpret: `T`, `e`,
 * `P`, `p`, `O`, `Z` and `I` name the timezone; `c` and `r` embed an offset
 * through those; `W` and `o` need ISO week rules.
 */
final readonly class DateFormat
{
    /**
     * Year `YyL`, month `nmMFt`, day `jdDlNwzS`, time `HGhgisAa`, fraction `vu`,
     * and the Unix second `U`.
     */
    private const string CHARACTERS = 'YyLnmMFtjdDlNwzSHGhgisAavuU';

    public static function apply(DateTimeImmutable $date, string $pattern): string
    {
        $rendered = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ('\\' === $character) {
                $index++;
                if ($index < $length) {
                    $rendered .= $pattern[$index];
                }

                continue;
            }

            // Only a character in the set is handed to the native formatter, so
            // one outside it cannot be interpreted here and ignored elsewhere.
            $rendered .= str_contains(self::CHARACTERS, $character) ? $date->format($character) : $character;
        }

        return $rendered;
    }
}
