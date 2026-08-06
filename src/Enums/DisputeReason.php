<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a dispute was raised — and therefore which correction a lost one owes.
 *
 * The two branches of a taxable-base correction look identical on the buyer's side and differ entirely on
 * the merchant's, so the reason is not color on a log line: it decides whether a second document is issued
 * against somebody who did nothing wrong.
 *
 * A buyer who never received what they paid for has a claim against the supply itself. The consideration is
 * handed back, both legs of the chain are corrected, and the merchant's own turnover moves with it.
 *
 * A FRAUDULENT charge is a different event wearing the same shape. The supply happened, the merchant
 * delivered, and the money is lost to a stolen card rather than returned to a customer. Correcting the
 * merchant-side document there would reduce the turnover of somebody who supplied exactly what they
 * promised — the platform's loss written onto the merchant's tax return.
 *
 * ## Unknown is not an error case, it is the safe one
 *
 * A provider adds reason codes over time, and an unrecognized code must not decide a tax treatment by
 * accident. Unknown maps to the branch that corrects BOTH legs — the conservative direction, because a
 * correction wrongly made is visible on a document somebody receives, while a correction wrongly SKIPPED is
 * silence, and silence is what nobody finds.
 */
enum DisputeReason: string
{
    /** The card was used without its holder's authority. The supply stands; the money is simply gone. */
    case Fraudulent = 'fraudulent';

    /** The holder does not recognize the charge. Treated as fraud until somebody says otherwise. */
    case Unrecognized = 'unrecognized';

    /** The buyer paid and received nothing. The supply itself failed. */
    case ProductNotReceived = 'product_not_received';

    /** A refund was promised and never made. The consideration is owed back. */
    case CreditNotProcessed = 'credit_not_processed';

    /** A code this package does not know. Deliberately fails toward the fuller correction — see above. */
    case Unknown = 'unknown';

    /** The reason code as the provider stated it, mapped to what this package knows — never guessed. */
    public static function fromProvider(?string $code): self
    {
        return self::tryFrom((string) $code) ?? self::Unknown;
    }

    /**
     * Which taxable-base correction a lost dispute of this kind owes.
     *
     * `Uncollectible` says the consideration will not arrive and may still: if the money turns up after all,
     * the correction is corrected back. That is the honest description of a fraud loss. `Repaid` says the
     * consideration was handed back and the matter is closed, which is what a failed supply is.
     */
    public function taxBaseChangeReason(): TaxBaseChangeReason
    {
        return match ($this) {
            self::Fraudulent, self::Unrecognized => TaxBaseChangeReason::Uncollectible,
            self::ProductNotReceived, self::CreditNotProcessed, self::Unknown => TaxBaseChangeReason::Repaid,
        };
    }

    /**
     * Whether the merchant's own settlement document is corrected too.
     *
     * False exactly where the merchant delivered and the platform carries the loss. This is the question the
     * reason exists to answer; everything else about it is bookkeeping.
     */
    public function correctsTheMerchantLeg(): bool
    {
        return $this->taxBaseChangeReason() === TaxBaseChangeReason::Repaid;
    }
}
