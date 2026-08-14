<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\Models\WithdrawalConsentRecord;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * Where a buyer's pre-provision declarations are written down and read back.
 *
 * The consumer records the consent at ITS checkout, because that is the only place the declarations are
 * actually made — this package never renders the checkbox and never will; the notice wording is the
 * operator's and their adviser's. What the package owns is that the answer survives, in one shape, keyed
 * the way the grant path already looks things up.
 *
 * ## Recording is idempotent, and it keeps the FIRST answer
 *
 * A retried checkout submits the same declarations again. Overwriting would move `given_at` forward and
 * rewrite the notice version to today's, which is exactly the reinterpretation the version exists to
 * prevent — the buyer consented under the words they were shown the first time. So a second record for the
 * same purchase is a no-op that returns what is already on file.
 */
final readonly class WithdrawalConsentLedger
{
    /**
     * Write the declarations down, once, and hand back what is on file.
     *
     * @param  Model  $owner  the buyer
     * @param  string  $reference  the purchase, in the shape the grant path keys on
     */
    public function record(Model $owner, string $reference, WithdrawalConsent $consent): WithdrawalConsent
    {
        $record = WithdrawalConsentRecord::query()->firstOrCreate(
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'reference' => $reference,
            ],
            [
                'consented_to_immediate_provision' => $consent->consentedToImmediateProvision,
                'acknowledged_forfeiture' => $consent->acknowledgedForfeiture,
                'notice_version' => $consent->noticeVersion,
                // The wording, beside the version that names it. A receipt has to repeat what the buyer
                // actually read, and a version identifier cannot produce that on its own.
                'immediate_provision_notice' => $consent->immediateProvisionNotice,
                'forfeiture_notice' => $consent->forfeitureNotice,
                'given_at' => $consent->givenAt,
            ],
        );

        return $record->toConsent();
    }

    /**
     * What this buyer declared for this purchase, or null when nothing is on file.
     *
     * Null is the answer the gate needs: on a work whose right of withdrawal ends at provision, no record
     * means no provision. It is deliberately not an exception — "nobody consented" is an ordinary state on
     * every install that has not turned a consumer-rights profile on, and most have not.
     */
    public function for(Model $owner, string $reference): ?WithdrawalConsent
    {
        return WithdrawalConsentRecord::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('reference', $reference)
            ->first()
            ?->toConsent();
    }

    /**
     * What this buyer declared for the purchase behind a PAYMENT reference.
     *
     * The receipt is where the law wants the two declarations repeated on a durable medium, and the receipt
     * knows the payment — `PaymentSucceeded` carries `pi_…`, while the consent is keyed on the checkout
     * reference a redelivered webhook repeats. Those are different strings, so a receipt cannot find the
     * consent by the key it holds.
     *
     * The link exists: an add-on purchase row carries both. This walks it, so an adopter writing their own
     * `ReceiptNotifier` does not have to rediscover the join -- and, more to the point, does not have to
     * guess that one is needed at all. A receipt that quietly omitted the declarations would look complete.
     *
     * Null when nothing is on file, which covers the ordinary cases: a payment for something that is not an
     * add-on, an install with no consumer-rights profile, a purchase made before the declarations were
     * collected.
     */
    public function forPayment(Model $owner, string $paymentReference): ?WithdrawalConsent
    {
        $purchase = AddonPurchase::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('payment_reference', $paymentReference)
            ->first();

        if (! $purchase instanceof AddonPurchase) {
            return null;
        }

        // The MINTED key first. A declaration is recorded before the buyer leaves for the provider, so it
        // cannot be keyed on a reference that does not exist yet -- the package mints one, and the purchase
        // carries it home. Falling straight through to `reference` would answer null for every purchase made
        // that way, and null on this path reads as "the buyer declared nothing": wrong, and indistinguishable
        // from the truth.
        //
        // The session reference stays as the second reading, for an install that records against it out of
        // band. That is the only other shape this ledger has ever been written in.
        return $this->for($owner, $purchase->declaration_reference ?? $purchase->reference);
    }
}
