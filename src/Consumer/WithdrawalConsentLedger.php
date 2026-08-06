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

        return $purchase instanceof AddonPurchase ? $this->for($owner, $purchase->reference) : null;
    }
}
