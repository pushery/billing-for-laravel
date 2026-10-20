<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Sets a routed subscription cycle's commission on its net while the provider still takes a change to the invoice.
 *
 * The configured rate is a net rate. A destination subscription can only state it as a percentage, which the provider
 * applies to the invoice total, the buyer's tax included, so on 119.00 at 19% with a 10% rate it would withhold 11.90
 * where the commission is 10.00. A drafted invoice still takes an absolute fee, and this is the seam that sets it,
 * before anything is withheld: no refund follows, no second movement, and nothing for a reconciliation to explain.
 *
 * Whatever is not set keeps the provider's percentage of the total, and the paid cycle's row states that as the base
 * it was, until the commission on the tax is given back after the payment. A draft finalized in the meantime, a cycle
 * that charged no tax or names no single rate, a subscription not priced by a percentage: each leaves the invoice as
 * it is, and none of them is an error.
 */
interface TakesCycleCommissionOnNet
{
    /**
     * Set the commission of a drafted cycle on the net its invoice's rate separates, where it still can be.
     *
     * @param  string  $invoiceReference  the provider's own invoice id, as the drafted-invoice event names it
     */
    public function onDraft(string $invoiceReference): void;
}
