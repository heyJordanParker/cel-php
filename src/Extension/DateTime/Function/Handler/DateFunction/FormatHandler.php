<?php

declare(strict_types=1);

namespace Cel\Extension\DateTime\Function\Handler\DateFunction;

use Cel\Exception\EvaluationException;
use Cel\Extension\DateTime\DateFormat;
use Cel\Function\FunctionOverloadHandlerInterface;
use Cel\Syntax\Member\CallExpression;
use Cel\Value\FloatValue;
use Cel\Value\IntegerValue;
use Cel\Value\NullValue;
use Cel\Value\StringValue;
use Cel\Value\TimestampValue;
use Cel\Value\Value;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psl\DateTime\DateTime;
use Psl\DateTime\Timezone;
use Psl\Str;
use Throwable;

use function floor;
use function intdiv;
use function round;

/**
 * Renders a date as text: `date(value)`, `date(value, format)`, or `date()` for
 * the current moment.
 *
 * The value may be a timestamp, a Unix second count, or a parsable string; null
 * and the empty string mean now, so a field that is not set yet still renders a
 * date rather than nothing.
 */
final readonly class FormatHandler implements FunctionOverloadHandlerInterface
{
    /**
     * @param non-empty-string $timezone The timezone dates are rendered in.
     * @param non-empty-string $defaultFormat The format used when a call gives none.
     */
    public function __construct(
        private string $timezone,
        private string $defaultFormat,
    ) {}

    /**
     * @param CallExpression $call The call expression.
     * @param list<Value> $arguments The function arguments.
     *
     * @return StringValue The rendered date.
     *
     * @throws EvaluationException If the value cannot be read as a date.
     */
    #[Override]
    public function __invoke(CallExpression $call, array $arguments): StringValue
    {
        $value = $arguments[0] ?? new NullValue();
        $format = $arguments[1] ?? null;

        $pattern = $format instanceof StringValue && '' !== $format->value
            ? $format->value
            : $this->defaultFormat;

        return new StringValue(DateFormat::apply($this->read($call, $value), $pattern));
    }

    /**
     * @throws EvaluationException If the value cannot be read as a date.
     */
    private function read(CallExpression $call, Value $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone($this->timezone);

        try {
            if ($value instanceof TimestampValue) {
                $nanoseconds = DateTime::fromTimestamp($value->value, Timezone::UTC)->getNanoseconds();

                return $this->instant($value->value->getSeconds(), intdiv($nanoseconds, 1000), $timezone);
            }

            if ($value instanceof IntegerValue) {
                return $this->instant($value->value, 0, $timezone);
            }

            if ($value instanceof FloatValue) {
                // Floor rather than truncate, so a moment before 1970 keeps a
                // non-negative fraction: -1.5 is one and a half seconds before
                // the epoch, which is second -2 plus 500000 microseconds.
                $seconds = (int) floor($value->value);

                return $this->instant(
                    $seconds,
                    (int) round(($value->value - $seconds) * 1_000_000),
                    $timezone,
                );
            }

            if ($value instanceof NullValue) {
                return new DateTimeImmutable('now', $timezone);
            }

            if ($value instanceof StringValue) {
                return '' === $value->value
                    ? new DateTimeImmutable('now', $timezone)
                    : new DateTimeImmutable($value->value, $timezone);
            }
        } catch (Throwable $exception) {
            throw new EvaluationException($exception->getMessage(), $call->getSpan(), $exception);
        }

        throw new EvaluationException(
            Str\format('date() cannot read a `%s` as a date', $value->getType()),
            $call->getSpan(),
        );
    }

    /**
     * The instant a whole second count and a microsecond remainder name.
     *
     * `@seconds` carries no sub-second part, so the remainder is added after,
     * which keeps `v` and `u` renderable rather than always zero.
     */
    private function instant(int $seconds, int $microseconds, DateTimeZone $timezone): DateTimeImmutable
    {
        $instant = new DateTimeImmutable('@' . $seconds);

        if (0 !== $microseconds) {
            $instant = $instant->modify('+' . $microseconds . ' microseconds');
        }

        return $instant->setTimezone($timezone);
    }
}
