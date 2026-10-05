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
 *
 * `occurredAt` is the provider event's own timestamp (Unix seconds), where the provider gives one. Reports
 * arrive out of order, and the stored capabilities take a report only when it is not older than the one they
 * were last taken from.
 */
final readonly class MerchantAccountUpdated implements BillingDomainEvent
{
    public function __construct(
        public MerchantAccountReference $account,
        public ?int $occurredAt = null,
    ) {}
}
