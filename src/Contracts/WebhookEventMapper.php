<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Http\Request;
use Pushery\Billing\Events\BillingDomainEvent;

/**
 * Translates a verified provider webhook into zero or more neutral domain events. This is the one
 * place provider vocabulary (`customer.subscription.updated`, `invoice.payment_failed`, …) is turned
 * into the stable events effects listen on, so the same effect works for every driver. Returning
 * nothing is normal — an event with no neutral meaning is simply ignored.
 */
interface WebhookEventMapper
{
    /** @return iterable<BillingDomainEvent> */
    public function map(Request $request): iterable;
}
