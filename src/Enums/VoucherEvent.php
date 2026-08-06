<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What happened to a voucher whose tax treatment is not fixed until it is used.
 *
 * Three events, three different bookings — and the reason they cannot be collapsed is that only one of them
 * is a supply. Issuing takes money against a promise, redeeming performs the promise, and expiry keeps money
 * for a promise nobody called in.
 */
enum VoucherEvent: string
{
    /** Money taken against a promise. No supply yet, so no tax yet. */
    case Issued = 'issued';

    /** The promise performed: this is where the sale, and its tax, happen. */
    case Redeemed = 'redeemed';

    /** Never used, and now it cannot be. What is left is income, not turnover — no supply was ever made. */
    case Expired = 'expired';
}
