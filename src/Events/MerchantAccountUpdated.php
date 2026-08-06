<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The provider has reported what a merchant's account can and cannot do.
 *
 * It carries the capabilities as REPORTED, not a decision about them: the effect that stores them and the
 * gate that reads them are separate, so an event can be replayed from the stored payload months later and
 * still mean the same thing.
 */
final readonly class MerchantAccountUpdated implements BillingDomainEvent
{
    public function __construct(public MerchantAccountReference $account) {}
}
