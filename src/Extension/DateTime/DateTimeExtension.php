<?php

declare(strict_types=1);

namespace Cel\Extension\DateTime;

use Cel\Extension\ExtensionInterface;
use Override;

/**
 * @api
 */
final readonly class DateTimeExtension implements ExtensionInterface
{
    /**
     * @param non-empty-string $timezone The timezone `date()` renders in. Every
     *                                   other function here works in UTC, as it did
     *                                   before, and is unaffected.
     * @param non-empty-string $defaultFormat The format `date()` uses when a call gives none.
     */
    public function __construct(
        private string $timezone = 'UTC',
        private string $defaultFormat = Function\DateFunction::ISO_8601,
    ) {}

    /**
     * @inheritDoc
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new Function\DateFunction($this->timezone, $this->defaultFormat),
            new Function\NowFunction(),
            new Function\TimestampFunction(),
            new Function\DurationFunction(),
            new Function\GetSecondsFunction(),
            new Function\GetMinutesFunction(),
            new Function\GetHoursFunction(),
            new Function\GetMillisecondsFunction(),
            new Function\GetFullYearFunction(),
            new Function\GetMonthFunction(),
            new Function\GetDayOfYearFunction(),
            new Function\GetDayOfMonthFunction(),
            new Function\GetDayOfWeekFunction(),
            new Function\GetDateFunction(),
        ];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getBinaryOperatorOverloads(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getUnaryOperatorOverloads(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getMessageTypes(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getValueResolvers(): array
    {
        return [];
    }
}
