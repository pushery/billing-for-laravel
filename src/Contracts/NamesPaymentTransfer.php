<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Ask the provider which transfer a payment's share went out on.
 *
 * On a destination charge the provider creates that transfer itself, as the payment settles. A payment that
 * settles at once reports it in the same answer. One that is confirmed later does not: the confirmation names the
 * payment, and the transfer lives on the payment's charge, so it has to be asked for.
 *
 * A separate interface for the reason `ReportsMovedShares` gives: a driver may honestly support moving money
 * without supporting every read around it, and the type system answers which before anything runs.
 */
interface NamesPaymentTransfer
{
    /**
     * The provider's id for the transfer that carried this payment's share, or null when it names none.
     *
     * The reference is the one the ledger holds for the payment, exactly as the payment lane recorded it. Null is
     * an answer, not an error: a payment that was never routed, or whose transfer does not exist yet, has none.
     */
    public function transferOfPayment(string $paymentReference): ?string;
}
