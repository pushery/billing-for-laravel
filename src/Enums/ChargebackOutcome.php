<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where one chargeback of a payment stands, as its provider reported it last.
 */
enum ChargebackOutcome: string
{
    /** The chargeback stands, and the provider has reported no decision on it. */
    case Open = 'open';

    /** The money came back to the merchant: Mollie reversed the chargeback, or the merchant won the Stripe dispute. */
    case Reversed = 'reversed';

    /** The merchant lost the Stripe dispute, and the bank keeps the money. Mollie reports no such decision. */
    case Lost = 'lost';
}
