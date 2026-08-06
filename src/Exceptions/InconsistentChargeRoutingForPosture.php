<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use RuntimeException;

/**
 * The money is routed one way and the seller is declared to be somebody else.
 *
 * The two are independent axes on purpose — how a provider moves money says nothing about who the law
 * treats as the seller, and for electronic services the seller is assigned regardless of how the money
 * flows. Independent axes can diverge, and when they do the result is not an error anybody sees: it is a
 * set of documents that does not match the money, discovered in an audit rather than in a log.
 *
 * Refused when the transaction is resolved, before any money moves. Refusing at document time would be
 * refusing after the fact.
 */
final class InconsistentChargeRoutingForPosture extends RuntimeException
{
    /** @param  list<string>  $permitted */
    public static function forPair(ChargeType $type, SellerOfRecordPosture $posture, array $permitted): self
    {
        $allowed = $permitted === [] ? 'none' : implode(', ', $permitted);

        return new self(
            "A [{$type->value}] charge cannot be used while the seller posture is [{$posture->value}] ".
            "(this charge type permits: {$allowed}). The charge type decides who the provider treats as ".
            'the merchant of record; the posture decides who the documents name as the seller. They are '.
            'separate answers to separate questions, and a pair that disagrees produces a receipt and a '.
            'settlement describing different transactions — which nothing later in the run will notice.'
        );
    }
}
