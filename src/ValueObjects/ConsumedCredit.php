<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\CreditReason;

/**
 * One credit a spend consumed, and what that credit was.
 *
 * A balance is fungible: a customer holding paid top-ups and a proration credit spends "balance",
 * not one or the other. But the two are different things in the books — a top-up is money somebody
 * paid, a proration credit is consideration being given back — so an offset against the mixed
 * balance has to be split before either half can be booked.
 *
 * The `source` travels because it is the whole point for a proration credit: the correction that
 * credit eventually needs belongs to the invoice it returned consideration FROM, at that invoice's
 * tax rate. A split that reported only "40 minor units were proration" would have to go looking for
 * the invoice again, and the ledger's own source is the only place that answer exists.
 */
final readonly class ConsumedCredit
{
    public function __construct(
        public CreditReason $reason,
        public Money $amount,
        public ?CreditSource $source = null,
    ) {}
}
