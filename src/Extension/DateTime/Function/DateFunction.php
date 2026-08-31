<?php

declare(strict_types=1);

namespace Cel\Extension\DateTime\Function;

use Cel\Extension\DateTime\Function\Handler\DateFunction\FormatHandler;
use Cel\Function\FunctionInterface;
use Cel\Value\ValueKind;
use Override;

final readonly class DateFunction implements FunctionInterface
{
    /**
     * The value kinds a date can be read from.
     *
     * @var list<ValueKind>
     */
    private const array VALUE_KINDS = [
        ValueKind::Timestamp,
        ValueKind::String,
        ValueKind::Integer,
        ValueKind::Float,
        ValueKind::Null,
    ];

    /**
     * ISO 8601 without an offset, which the format characters deliberately
     * cannot express. It is the neutral choice for a library that does not know
     * where its output will be read; a host with a display convention passes
     * its own.
     */
    public const string ISO_8601 = 'Y-m-d\TH:i:s';

    /**
     * @param non-empty-string $timezone The timezone dates are rendered in.
     * @param non-empty-string $defaultFormat The format used when a call gives none.
     */
    public function __construct(
        private string $timezone = 'UTC',
        private string $defaultFormat = self::ISO_8601,
    ) {}

    /**
     * @return non-empty-string
     */
    #[Override]
    public function getName(): string
    {
        return 'date';
    }

    #[Override]
    public function isIdempotent(): bool
    {
        // date() with no value reads the current moment.
        return false;
    }

    #[Override]
    public function getOverloads(): iterable
    {
        $handler = new FormatHandler($this->timezone, $this->defaultFormat);

        yield [] => $handler;

        foreach (self::VALUE_KINDS as $kind) {
            yield [$kind] => $handler;
            yield [$kind, ValueKind::String] => $handler;
        }
    }
}
