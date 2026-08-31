<?php

declare(strict_types=1);

namespace Cel\Extension\Core\BinaryOperator;

use Cel\Extension\Core\BinaryOperator\Handler\NumericPromotionHandler;
use Cel\Operator\BinaryOperatorOverloadHandlerInterface;
use Cel\Value\ValueKind;

/**
 * The mixed integer/float operand pairs every numeric operator accepts, each
 * served by the operator's own float handler through {@see NumericPromotionHandler}.
 *
 * Every numeric operator yields these alongside its identical-kind overloads, so
 * one definition covers comparison, equality and arithmetic alike.
 */
final readonly class NumericPromotion
{
    /**
     * @param BinaryOperatorOverloadHandlerInterface $float The operator's float/float handler.
     *
     * @return iterable<list<ValueKind>, BinaryOperatorOverloadHandlerInterface>
     */
    public static function overloads(BinaryOperatorOverloadHandlerInterface $float): iterable
    {
        $handler = new NumericPromotionHandler($float);

        yield [ValueKind::Integer, ValueKind::Float] => $handler;
        yield [ValueKind::Float, ValueKind::Integer] => $handler;
        yield [ValueKind::UnsignedInteger, ValueKind::Float] => $handler;
        yield [ValueKind::Float, ValueKind::UnsignedInteger] => $handler;
    }
}
