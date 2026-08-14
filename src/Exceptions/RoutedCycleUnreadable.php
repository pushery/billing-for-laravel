<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A routed subscription cycle was paid and the provider could not say what it withheld.
 *
 * ## Why this throws instead of returning
 *
 * The routed subscription lane READS the commission rather than computing it, and the entire justification
 * for that — three provider calls on every routed cycle — is the direction the two fail in.
 *
 * A computed commission that drifts from the provider's is a **plausible wrong number** sitting in a money
 * ledger. Nothing goes red; the figure flows into a clawback cap and a small-business judgement looking
 * exactly like a right one. A failed read is the opposite: it produces nothing, and nothing is visible.
 *
 * Returning quietly here would hand that advantage straight back. There would be no row, no error and no
 * trace — the same silence as before this lane existed, bought at the price of three provider calls.
 *
 * The effect runs queued, so throwing means the queue retries a transient failure and surfaces a permanent
 * one as a failed job. That is a thing an operator can see and act on. A missing ledger row is not.
 *
 * ## What it does NOT mean
 *
 * Not "the subscription is unrouted" — that is decided from the local subscription row before the provider
 * is asked at all, and it returns without raising anything. Reaching this means the cycle looked routed and
 * the provider still could not answer for it.
 */
final class RoutedCycleUnreadable extends RuntimeException
{
    public function __construct(
        public readonly string $invoiceReference,
        public readonly string $subscriptionReference,
    ) {
        parent::__construct(
            "The commission withheld on invoice '{$invoiceReference}' (subscription '{$subscriptionReference}') "
            .'could not be read back from the provider, so no ledger row was written. The reversal caps, the '
            .'earnings counter and the small-business judgement all read that table, and each would answer as '
            .'though this cycle had not happened. Check that the invoice still exists at the provider, that '
            .'the key may read it, and that the subscription still carries transfer_data.'
        );
    }

    public static function forInvoice(string $invoiceReference, string $subscriptionReference): self
    {
        return new self($invoiceReference, $subscriptionReference);
    }
}
