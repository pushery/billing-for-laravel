<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * The provider could not move a merchant's money from their connected balance to their bank.
 *
 * ## Why this is a different question from a transfer
 *
 * A TRANSFER moves money from the platform's balance to the merchant's connected balance; a PAYOUT moves it
 * from there to their bank. A payout fails on its own terms — a wrong IBAN, a closed account, a bank that
 * refuses it — with nothing wrong about the transfer that fed it. The journal knew what the platform had
 * sent and, since the provider-reversal event, what had been taken back. It did not know whether any of it
 * arrived.
 *
 * Between the two states there is a window where the merchant's money is neither on the platform nor at
 * their bank, and a failed payout leaves it there. The support case is "I have not been paid", and nothing
 * in the journal answered it.
 *
 * ## Only the failure, and that is a decision rather than an omission
 *
 * The success is the ordinary case and the provider's dashboard already shows it; recording every
 * `payout.paid` would add a row per merchant per cycle that nobody reads. The failure is the one an operator
 * has to answer for, so it is the one that lands here. The alternative — a table per payout, reconcilable
 * against a provider statement — is a reconciliation question and belongs to that line of work, not here: a
 * payout bundles many transfers, so it has no 1:1 relation to any recorded sale, and attributing it to one
 * would be exactly the wrong attribution.
 *
 * ## Attributed to the MERCHANT, never to a charge
 *
 * For the same reason. The connected account is the only honest subject of this event.
 */
final readonly class MerchantPayoutFailed implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        /** The connected account whose payout failed — the only honest subject of this event. */
        public string $accountReference,
        /** The provider's id for the payout, so a redelivery is recognizable and a support case is traceable. */
        public string $payoutReference,
        public int $amountMinor,
        public string $currency,
        /** The provider's machine-readable reason, where it gave one. */
        public ?string $failureCode = null,
        /** And its sentence, which is what an operator actually reads. */
        public ?string $failureMessage = null,
    ) {}
}
