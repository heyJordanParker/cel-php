<?php

declare(strict_types=1);

namespace Cel\Tests\Runtime;

use Cel\Exception\EvaluationException;
use Cel\Runtime\Configuration;
use Cel\Tests\Fixture\FieldProviderMessage;
use Cel\Value\BooleanValue;
use Cel\Value\ListValue;
use Cel\Value\NullValue;
use Cel\Value\StringValue;
use Cel\Value\Value;
use Override;

/**
 * Covers a message that answers its own fields: every operator that reads a
 * field asks the message at the moment the expression reaches that field, and
 * every operator that passes a message along hands back the same PHP object.
 */
final class FieldProviderTest extends RuntimeTestCase
{
    /**
     * @return iterable<string, array{
     *     0: string,
     *     1: array<string, mixed>,
     *     2: Value|EvaluationException,
     *     3?: null|Configuration
     * }>
     */
    #[Override]
    public static function provideEvaluationCases(): iterable
    {
        $customer = static fn(): FieldProviderMessage => new FieldProviderMessage([
            'name' => 'Ada',
            'email' => '',
            'address' => new FieldProviderMessage(['city' => 'London']),
        ]);

        // --- Member access ---

        yield 'dot reads a field' => ['m.name', ['m' => $customer()], new StringValue('Ada')];
        yield 'dot reads through a nested provider' => [
            'm.address.city',
            ['m' => $customer()],
            new StringValue('London'),
        ];
        yield 'dot on a missing field yields null' => ['m.missing', ['m' => $customer()], new NullValue()];

        // --- Optional member access ---

        yield 'optional dot holds a present field' => ['m.?name.value()', ['m' => $customer()], new StringValue('Ada')];
        yield 'optional dot is none for a missing field' => [
            'm.?missing.hasValue()',
            ['m' => $customer()],
            new BooleanValue(false),
        ];

        // --- Index ---

        yield 'index reads a field' => ['m["name"]', ['m' => $customer()], new StringValue('Ada')];
        yield 'index on a missing field yields null' => ['m["missing"]', ['m' => $customer()], new NullValue()];
        yield 'optional index holds a present field' => [
            'm[?"name"].value()',
            ['m' => $customer()],
            new StringValue('Ada'),
        ];
        yield 'optional index is none for a missing field' => [
            'm[?"missing"].hasValue()',
            ['m' => $customer()],
            new BooleanValue(false),
        ];

        // --- has() ---

        yield 'has finds a present field' => ['has(m.name)', ['m' => $customer()], new BooleanValue(true)];
        yield 'has misses an absent field' => ['has(m.missing)', ['m' => $customer()], new BooleanValue(false)];

        // --- Coalesce ---

        yield 'coalesce keeps a present field' => ['m.name ?? "there"', ['m' => $customer()], new StringValue('Ada')];
        yield 'coalesce falls back on an empty field' => [
            'm.email ?? "none"',
            ['m' => $customer()],
            new StringValue('none'),
        ];
        yield 'coalesce falls back on a missing field' => [
            'm.missing ?? "there"',
            ['m' => $customer()],
            new StringValue('there'),
        ];

        // --- Comprehensions ---

        $offers = [
            new FieldProviderMessage(['title' => 'a', 'active' => true]),
            new FieldProviderMessage(['title' => 'b', 'active' => false]),
            new FieldProviderMessage(['title' => 'c', 'active' => true]),
        ];

        yield 'filter then map reads fields per item' => [
            'offers.filter(o, o.active).map(o, o.title)',
            ['offers' => $offers],
            new ListValue([new StringValue('a'), new StringValue('c')]),
        ];
        yield 'exists reads fields per item' => [
            'offers.exists(o, o.title == "b")',
            ['offers' => $offers],
            new BooleanValue(true),
        ];
        yield 'all reads fields per item' => [
            'offers.all(o, has(o.title))',
            ['offers' => $offers],
            new BooleanValue(true),
        ];
    }

    public function testAFieldIsReadOnlyWhenTheExpressionReachesIt(): void
    {
        $message = new FieldProviderMessage(['name' => 'Ada', 'email' => 'ada@example.com', 'active' => true]);

        $this->evaluate('m.active ? m.name : m.email', ['m' => $message]);

        static::assertSame(['active', 'name'], $message->reads);
    }

    public function testHasAsksForPresenceWithoutReadingTheField(): void
    {
        $message = new FieldProviderMessage(['name' => 'Ada']);

        $result = $this->evaluate('has(m.name)', ['m' => $message])->result;

        static::assertTrue($result->getRawValue());
        static::assertSame([], $message->reads);
    }

    public function testEqualityIsObjectIdentity(): void
    {
        $first = new FieldProviderMessage(['name' => 'Ada']);
        $twin = new FieldProviderMessage(['name' => 'Ada']);

        static::assertTrue($this->evaluate('a == a', ['a' => $first])->result->getRawValue());
        static::assertFalse($this->evaluate('a == b', ['a' => $first, 'b' => $twin])->result->getRawValue());
        static::assertTrue($this->evaluate('a != b', ['a' => $first, 'b' => $twin])->result->getRawValue());
        static::assertTrue($this->evaluate('a in [b, a]', ['a' => $first, 'b' => $twin])->result->getRawValue());

        // Identity is decided without reading a field.
        static::assertSame([], $first->reads);
        static::assertSame([], $twin->reads);
    }

    public function testFilterReturnsTheOriginalObjects(): void
    {
        $first = new FieldProviderMessage(['active' => true]);
        $second = new FieldProviderMessage(['active' => false]);
        $third = new FieldProviderMessage(['active' => true]);

        $result = $this->evaluate('offers.filter(o, o.active)', ['offers' => [$first, $second, $third]])->result;

        static::assertSame([$first, $third], $result->getRawValue());
        static::assertSame(['active'], $second->reads);
    }

    public function testMapReturnsTheOriginalObjects(): void
    {
        $first = new FieldProviderMessage(['active' => true]);
        $second = new FieldProviderMessage(['active' => false]);

        $all = $this->evaluate('offers.map(o, o)', ['offers' => [$first, $second]])->result;
        $active = $this->evaluate('offers.map(o, o.active, o)', ['offers' => [$first, $second]])->result;

        static::assertSame([$first, $second], $all->getRawValue());
        static::assertSame([$first], $active->getRawValue());
    }

    public function testExistsAndAllBindTheOriginalObjects(): void
    {
        $first = new FieldProviderMessage([]);
        $second = new FieldProviderMessage([]);
        $variables = ['offers' => [$first, $second], 'pick' => $second];

        static::assertTrue($this->evaluate('offers.exists(o, o == pick)', $variables)->result->getRawValue());
        static::assertFalse($this->evaluate('offers.all(o, o == pick)', $variables)->result->getRawValue());
    }

    public function testAListLiteralKeepsTheOriginalObjects(): void
    {
        $first = new FieldProviderMessage([]);
        $second = new FieldProviderMessage([]);

        $result = $this->evaluate('[a, b][1]', ['a' => $first, 'b' => $second])->result;

        static::assertSame($second, $result->getRawValue());
    }

    public function testCoalesceReturnsTheOriginalObject(): void
    {
        $message = new FieldProviderMessage([]);

        $kept = $this->evaluate('m ?? "fallback"', ['m' => $message])->result;
        $fallenBack = $this->evaluate('missing ?? m', ['m' => $message, 'missing' => null])->result;

        static::assertSame($message, $kept->getRawValue());
        static::assertSame($message, $fallenBack->getRawValue());
    }

    public function testMapReadsOnlyTheFieldItNames(): void
    {
        $message = new FieldProviderMessage(['title' => 'a', 'body' => 'long', 'active' => true]);

        $result = $this->evaluate('offers.map(o, o.title)', ['offers' => [$message]])->result;

        static::assertInstanceOf(ListValue::class, $result);
        static::assertSame(['a'], $result->getRawValue());
        static::assertSame(['title'], $message->reads);
    }
}
