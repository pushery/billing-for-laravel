<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\ValueObjects\MandateCharge;
use Pushery\Billing\ValueObjects\OrderItemDraft;

/**
 * A charge of lines the application sets, collected against the owner's default mandate without a checkout.
 *
 * For an amount that is no cycle: usage billed on a document of its own after the month, or a service priced once it
 * is delivered. A contract of its own rather than a method on `BillingEngine`, which has implementations outside this
 * package. The Mollie driver binds it to the engine that bills its cycles; on a Stripe install it is not bound, so ask
 * the container first.
 */
interface ChargesOnTheMandate
{
    /**
     * Charge an owner once for the lines given, as an order of its own, and collect it against their default mandate.
     *
     * The same reference again returns the order the first call wrote and charges nothing. Lines that come to nothing
     * or less write no order and no document.
     *
     * @param  non-empty-list<OrderItemDraft>  $lines  what the order states, all in one currency, none of them a credit line
     * @param  string  $reference  the application's key for this charge, unique for the owner
     * @param  TaxArchetype  $supplies  what the lines supply, which their document is taxed as
     * @param  DateTimeInterface|null  $from  the first day of the period the lines supplied, or null where they name none
     * @param  DateTimeInterface|null  $until  the day after its last, as the period of a subscription ends where the
     *                                         next one begins; both are read as dates, their times do not count
     */
    public function chargeOnTheMandate(Model $owner, array $lines, string $reference, TaxArchetype $supplies, ?DateTimeInterface $from = null, ?DateTimeInterface $until = null): MandateCharge;
}
