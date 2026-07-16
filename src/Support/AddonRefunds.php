<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Catalogs\ConfigAddonCatalog;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\ValueObjects\AddonReversal;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * Claws back the credit granted for a one-time add-on when its charge is refunded, disputed-and-lost, or
 * an admin refunds it. It reverses the purchase, debits the owner's credit and writes the audit line in
 * ONE transaction, so a failed debit rolls the reversal mark back — otherwise a mid-way failure would
 * leave the purchase marked reversed while the credit was never clawed back, and the provider's retry
 * (which finds it already marked) would never debit it.
 *
 * Reversal is matched on the provider PAYMENT reference (a PaymentIntent) the purchase recorded, and is
 * idempotent by the ledger's cumulative-refunded tracking — a partial refund, a lost dispute after a
 * partial refund, and a redelivery each claw back only what has not been reversed yet. A payment that is
 * not a tracked add-on (a subscription invoice, an ad-hoc charge) matches no purchase and reverses
 * nothing.
 */
final readonly class AddonRefunds
{
    public function __construct(
        private AddonPurchases $purchases,
        private CreditLedger $ledger,
        private BillingEventLog $log,
        private CreditSync $creditSync,
        private ConfigAddonCatalog $addons,
        private PrepaidLedger $prepaid,
    ) {}

    /**
     * Reverse the add-on credit for a refunded/disputed payment up to the cumulative reversed total,
     * returning the reversal actually applied this time, or null when there is nothing to reverse.
     */
    public function reverse(string $paymentReference, Money $cumulativeReversed, ?string $reason = null, AuditSource $source = AuditSource::Webhook, ?Model $actor = null): ?AddonReversal
    {
        $reversal = DB::transaction(function () use ($paymentReference, $cumulativeReversed, $reason, $source, $actor): ?AddonReversal {
            $reversal = $this->purchases->reverse($paymentReference, $cumulativeReversed, $reason);

            if (! $reversal instanceof AddonReversal) {
                return null;
            }

            $grant = $this->addons->grantsFor($reversal->addonKey);

            // An add-on that granted UNITS is clawed back in units, not money — it never touched the money
            // balance. Only the units the owner has NOT consumed come back (PrepaidLedger caps at the
            // balance): the ones they already spent delivered their value, and taking those back after
            // refunding the purchase would charge them twice for one thing.
            if ($grant instanceof UnitGrant) {
                $taken = $this->prepaid->clawBack(
                    $reversal->owner,
                    $grant->meterKey,
                    $grant->unitsFor($reversal->amount->minorUnits, $reversal->purchaseMinor),
                );

                $this->log->record('addon.units_clawed_back', $reversal->owner, [
                    'payment_reference' => $paymentReference,
                    'addon' => $reversal->addonKey,
                    'meter' => $grant->meterKey,
                    'units' => $taken,
                    'reason' => $reason,
                ], $source, $actor);

                return $reversal;
            }

            $this->ledger->debit($reversal->owner, $reversal->amount);

            $this->log->record('addon.reversed', $reversal->owner, [
                'payment_reference' => $paymentReference,
                'amount' => $reversal->amount->minorUnits,
                'currency' => $reversal->amount->currency,
                'reason' => $reason,
            ], $source, $actor);

            return $reversal;
        });

        // A units add-on never credited the provider balance, so there is nothing to claw back there.
        if ($reversal instanceof AddonReversal && $this->addons->grantsFor($reversal->addonKey) instanceof UnitGrant) {
            return $reversal;
        }

        if ($reversal instanceof AddonReversal) {
            // Mirror the clawback onto the provider balance, AFTER the local debit commits so a slow provider
            // never holds the transaction open. Negated: the customer's credit went down, so their provider
            // balance must too. Only the delta is pushed (a partial refund claws back only its part), but the
            // idempotency key is the CUMULATIVE reversed total, not the delta: two equal partial refunds of the
            // same charge produce the same delta, so keying on the delta would give them the same key and Stripe
            // would silently drop the second clawback — the customer keeps that credit. The cumulative total is
            // monotonic, so each step is unique while a redelivery of the same step repeats its key.
            $this->creditSync->push(
                $reversal->owner,
                $reversal->amount->negated(),
                'reverse:'.$paymentReference.':'.$cumulativeReversed->minorUnits,
            );
        }

        return $reversal;
    }
}
