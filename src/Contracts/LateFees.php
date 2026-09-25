<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Dunning\LocalLateFees;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * Applies a dunning late fee so it is collected with the owner's NEXT payment. A seam because HOW a fee
 * is collected is a driver concern — Stripe adds a pending invoice item. The reference is a stable
 * idempotency key so re-running the dunning advance cannot charge the same fee twice. The package binds a
 * no-op default; a driver that can bill a fee replaces it.
 *
 * The Mollie driver opens the fee as an order of its own ({@see LocalLateFees}), which its local engine
 * charges once a cycle of the owner has been paid. The fee is compensation rather than the price of a
 * supply, so its document states no tax.
 */
interface LateFees
{
    /**
     * @param  ?Subscription  $subscription  the subscription in arrears, so a driver can put the fee on that
     *                                       subscription's own next invoice rather than the customer's next one
     */
    public function apply(Model $owner, Money $fee, string $reference, string $description, ?Subscription $subscription = null): void;
}
