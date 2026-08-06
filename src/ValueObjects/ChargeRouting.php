<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\ChargeType;

/**
 * Where a payment's money goes and what the platform keeps of it.
 *
 * The whole marketplace dimension of the money seams is this one optional value. A payment with no routing
 * is today's payment, unchanged down to the fields sent to the provider — which is what lets a single
 * seller keep using contracts that a marketplace also uses, rather than the package growing a second set.
 *
 * It travels as a value object rather than an account id and an amount, because those two are meaningless
 * apart: an account with no fee and a fee with no account are both configuration mistakes that a pair of
 * loose arguments would carry all the way to the provider before anyone noticed.
 */
final readonly class ChargeRouting
{
    /**
     * @param  MerchantAccountReference  $destination  the account the merchant's share is owed to
     * @param  Money  $applicationFee  what the platform keeps — never negative, never more than the payment
     * @param  ChargeType  $type  which shape the payment takes, and so who carries the dispute
     * @param  bool  $onBehalfOf  whether the provider treats the merchant as the merchant of record for
     *                            the payment. It changes the country the payment is processed in, that
     *                            country's fee schedule, and the descriptor, address and phone the buyer
     *                            sees on their statement. It does NOT move the processing fee or the
     *                            dispute off the platform — on a destination charge both stay with the
     *                            platform whatever this says, and the country-specific fee is still billed
     *                            to the platform account. This parameter has now been wrong in BOTH
     *                            directions: first described as cosmetic, then as the axis fee and
     *                            liability follow. See ChargeType::Destination for what the provider
     *                            actually documents, and for what is still unverified against a real
     *                            payload.
     */
    public function __construct(
        public MerchantAccountReference $destination,
        public Money $applicationFee,
        public ChargeType $type = ChargeType::Destination,
        public bool $onBehalfOf = false,
    ) {
        if ($applicationFee->isNegative()) {
            throw new InvalidArgumentException('A platform fee cannot be negative.');
        }
    }

    /**
     * Check the fee against the payment it is taken from.
     *
     * Deliberately not done in the constructor: the routing is built before the amount is always known, and
     * a rule that can only be checked against the payment belongs where the payment is. A fee larger than
     * the payment would leave the merchant owing money on a sale, which no provider will do and which is
     * better refused here than turned into a provider error with no context.
     */
    public function assertFitsWithin(Money $amount): void
    {
        if ($this->applicationFee->currency !== $amount->currency) {
            throw new InvalidArgumentException(
                "A platform fee in {$this->applicationFee->currency} cannot be taken from a payment in {$amount->currency}."
            );
        }

        if ($this->applicationFee->greaterThan($amount)) {
            throw new InvalidArgumentException(
                'A platform fee cannot exceed the payment it is taken from.'
            );
        }
    }
}
