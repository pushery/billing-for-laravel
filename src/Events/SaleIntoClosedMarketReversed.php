<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\ValueObjects\RefundResult;

/**
 * A sale the provider taxed in a market that is not open was undone.
 *
 * Dispatched after the subscription was ended and the payment refunded, so an application can tell the buyer that
 * their country is not served yet. `country` is the ISO code the provider reported, or `unknown` when it reported
 * none. `refund` is null when there was no payment to return yet. A refund the provider refused arrives with
 * `successful` false: the buyer is still owed the money, and the audit trail names the charge.
 */
final readonly class SaleIntoClosedMarketReversed implements BillingDomainEvent
{
    public function __construct(
        public Model $owner,
        public string $country,
        public MarketAccess $state,
        public string $saleReference,
        public bool $subscriptionEnded,
        public ?RefundResult $refund,
    ) {}
}
