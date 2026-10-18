<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Jobs\PushCreditToProvider;
use Pushery\Billing\Marketplace\CreditTopUpVolume;
use Pushery\Billing\ValueObjects\AddonReversal;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * Claws back the credit granted for a one-time add-on when its charge is refunded, disputed-and-lost, or
 * an admin refunds it. It reverses the purchase, debits the owner's credit and writes the audit line in
 * one transaction, so a failed debit rolls the reversal mark back — otherwise a mid-way failure would
 * leave the purchase marked reversed while the credit was never clawed back, and the provider's retry
 * (which finds it already marked) would never debit it.
 *
 * Reversal is matched on the provider payment reference (a PaymentIntent) the purchase recorded, and is
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
        /** The bound catalog, the one the purchase was credited from (see CreditAddonPurchase). */
        private AddonCatalog $addons,
        private PrepaidLedger $prepaid,
        /** The register of works, resolved from the container when the caller supplied none. */
        private ?AddonContentMap $content = null,
    ) {}

    /**
     * Whether the reversed purchase credited money, so a refund takes back from the balance exactly what the
     * purchase put there and nothing it never did.
     *
     * Read off the purchase, which recorded it when it was made: the catalog may have changed since, and an add-on
     * renamed or taken out of sale still credited money when it was bought. A purchase recorded without the answer
     * is decided the way the purchase decided it, from the catalog, and a key the catalog no longer names takes
     * nothing off the balance, because money that did not provably come from this purchase is the owner's.
     */
    private function creditedMoney(AddonReversal $reversal): bool
    {
        return $reversal->moneyCredit ?? CreditTopUpVolume::creditsMoney($this->addons, $this->content ?? Container::getInstance()->make(AddonContentMap::class), $reversal->addonKey);
    }

    /**
     * The usage units the reversed purchase granted: the ones it recorded, which may be none, and for a purchase
     * recorded without its answers, what the catalog grants for its key today.
     */
    private function grantOf(AddonReversal $reversal): ?UnitGrant
    {
        return $reversal->moneyCredit === null ? $this->addons->grantsFor($reversal->addonKey) : $reversal->granted;
    }

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

            // A purchase that credited money is taken back in money, whatever its key grants today.
            $credited = $this->creditedMoney($reversal);
            $grant = $credited ? null : $this->grantOf($reversal);

            // An add-on that granted units is clawed back in units, not money — it never touched the money
            // balance. Only the units the owner has not consumed come back (PrepaidLedger caps at the
            // balance): the ones they already spent delivered their value, and taking those back after
            // refunding the purchase would charge them twice for one thing.
            if ($grant instanceof UnitGrant) {
                $taken = $this->prepaid->clawBack(
                    $reversal->owner,
                    $grant->meterKey,
                    $grant->unitsFor($reversal->amount->minorUnits, $reversal->purchaseMinor),
                );

                // On the purchase, so a chargeback the bank takes back later gives back exactly these.
                $this->purchases->recordClawedBack($paymentReference, $taken);

                $this->log->record('addon.units_clawed_back', $reversal->owner, [
                    'payment_reference' => $paymentReference,
                    'addon' => $reversal->addonKey,
                    'meter' => $grant->meterKey,
                    'units' => $taken,
                    'reason' => $reason,
                ], $source, $actor);

                return $reversal;
            }

            // A purchase that credited nothing, a work or a sale of the application's own, puts nothing on the balance
            // to take back. The money returns to the buyer through the provider, and what was handed over is taken
            // back by whoever handed it over.
            if (! $credited) {
                $this->log->record('addon.purchase_reversed', $reversal->owner, [
                    'payment_reference' => $paymentReference,
                    'addon' => $reversal->addonKey,
                    'amount' => $reversal->amount->minorUnits,
                    'currency' => $reversal->amount->currency,
                    'reason' => $reason,
                ], $source, $actor);

                return $reversal;
            }

            $this->ledger->debit($reversal->owner, $reversal->amount, CreditReason::AddonReversal);

            $this->log->record('addon.reversed', $reversal->owner, [
                'payment_reference' => $paymentReference,
                'amount' => $reversal->amount->minorUnits,
                'currency' => $reversal->amount->currency,
                'reason' => $reason,
            ], $source, $actor);

            return $reversal;
        });

        // Only money credit reached the provider balance, so only money credit is clawed back there.
        if ($reversal instanceof AddonReversal && ! $this->creditedMoney($reversal)) {
            return $reversal;
        }

        if ($reversal instanceof AddonReversal) {
            // Mirror the clawback onto the provider balance, after the local debit commits so a slow provider
            // never holds the transaction open: the job is queued once the outermost transaction has committed,
            // which on a webhook is the run's own and not the one above. Negated: the customer's credit went down, so their provider
            // balance must too. Only the delta is pushed (a partial refund claws back only its part), but the
            // idempotency key is the cumulative reversed total, not the delta: two equal partial refunds of the
            // same charge produce the same delta, so keying on the delta would give them the same key and Stripe
            // would silently drop the second clawback — the customer keeps that credit. The cumulative total is
            // monotonic, so each step is unique while a redelivery of the same step repeats its key.
            Bus::dispatch(new PushCreditToProvider(
                $reversal->owner,
                $reversal->amount->negated(),
                'reverse:'.$paymentReference.':'.$cumulativeReversed->minorUnits,
            ));
        }

        return $reversal;
    }

    /**
     * Give back what a reversal took once the payment no longer stands reversed that far, down to its standing reversed
     * total, returning what was given back this time, or null when there is nothing to give back.
     *
     * The way back of reverse(), in one transaction and with its three cases. A purchase that credited money gets it
     * back on the balance, mirrored onto the provider balance after the commit. One that granted units gets back the
     * units its reversal clawed back, which pay off an overdraft first like a grant. One that credited nothing has only
     * its row restored. Raised for a chargeback the bank took back, when the customer has paid after all.
     */
    public function restore(string $paymentReference, Money $standingReversed, ?string $dispute = null, AuditSource $source = AuditSource::Webhook): ?AddonReversal
    {
        $restored = DB::transaction(function () use ($paymentReference, $standingReversed, $dispute, $source): ?AddonReversal {
            $restored = $this->purchases->restore($paymentReference, $standingReversed);

            if (! $restored instanceof AddonReversal) {
                return null;
            }

            $credited = $this->creditedMoney($restored);
            $grant = $credited ? null : $this->grantOf($restored);

            if ($grant instanceof UnitGrant) {
                $this->prepaid->giveBack($restored->owner, $grant->meterKey, $restored->units);

                $this->log->record('addon.units_given_back', $restored->owner, [
                    'payment_reference' => $paymentReference,
                    'addon' => $restored->addonKey,
                    'meter' => $grant->meterKey,
                    'units' => $restored->units,
                    'dispute' => $dispute,
                ], $source);

                return $restored;
            }

            if (! $credited) {
                $this->log->record('addon.purchase_restored', $restored->owner, [
                    'payment_reference' => $paymentReference,
                    'addon' => $restored->addonKey,
                    'amount' => $restored->amount->minorUnits,
                    'currency' => $restored->amount->currency,
                    'dispute' => $dispute,
                ], $source);

                return $restored;
            }

            $this->ledger->credit($restored->owner, $restored->amount, CreditReason::AddonReversalUndone);

            $this->log->record('addon.restored', $restored->owner, [
                'payment_reference' => $paymentReference,
                'amount' => $restored->amount->minorUnits,
                'currency' => $restored->amount->currency,
                'dispute' => $dispute,
            ], $source);

            return $restored;
        });

        if ($restored instanceof AddonReversal && $this->creditedMoney($restored)) {
            // Mirrored like the clawback it undoes: after the commit, and keyed on the standing total, so each step is
            // pushed once while a redelivery of the same step repeats its key.
            Bus::dispatch(new PushCreditToProvider(
                $restored->owner,
                $restored->amount,
                'restore:'.$paymentReference.':'.$standingReversed->minorUnits,
            ));
        }

        return $restored;
    }
}
