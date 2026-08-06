<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Models\ProviderFee;
use Pushery\Billing\ValueObjects\Money;

/**
 * Keeps what the provider charged for handling a dispute.
 *
 * The fee arrived on the event and lived nowhere afterwards, so nothing could post it to an account and
 * nobody could reconcile it against a provider statement. It is the platform's own cost — a service supplied
 * to it, taxed in its own country where the provider is established abroad — and a cost with no record is one
 * that shows up only as an unexplained difference at the end of a month.
 *
 * Recorded once per dispute. A redelivered webhook must not charge it again: a duplicated fee is not a
 * rounding difference but an expense that never happened, and because it books to an account that
 * self-assesses tax, it would invent that tax too. The claim is on the provider's own reference for the
 * dispute, which is the one identifier a redelivery is guaranteed to repeat.
 *
 * A chargeback carrying no fee writes nothing. Absent is not zero: the provider not saying what it charged
 * is a different fact from the provider charging nothing, and a zero row would assert the second.
 */
final readonly class RecordProviderFee
{
    public function __construct(private MerchantAccountDirectory $accounts) {}

    public function __invoke(ChargebackReceived $event): void
    {
        $fee = $event->feeAmount;

        if (! $fee instanceof Money || ! $fee->isPositive()) {
            return;
        }

        $merchant = $event->merchantReference === null
            ? null
            : $this->accounts->merchantForReference($event->merchantReference);

        // THE DISPUTE, not the charge it was raised against — and the fallback is what makes that safe.
        //
        // The claim was on the charge reference while this docblock, the migration and the docs all said
        // "once per dispute". A charge can carry more than one (only part of an order may be disputed), so a
        // second lost dispute found the first one's row and wrote nothing: real money the platform paid,
        // missing as an expense and as its reverse-charge position.
        //
        // Falling back to the charge keeps two things working that should not break. A consumer dispatching
        // this event by hand does not have to learn a new field, and a row written before this existed is
        // keyed on the charge and stays findable. The column means "what this fee arose over", and the
        // uniqueness it is under holds either way.
        $claim = $event->disputeReference ?? $event->reference;

        ProviderFee::query()->firstOrCreate(
            ['provider' => 'stripe', 'reference' => $claim],
            [
                'merchant_type' => $merchant?->getMorphClass(),
                'merchant_id' => $merchant?->getKey(),
                'currency' => $fee->currency,
                'amount_minor' => $fee->minorUnits,
                'cause' => $event->cause,
                'occurred_at' => Carbon::now(),
            ],
        );
    }
}
