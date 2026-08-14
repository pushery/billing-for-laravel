<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Guards;

/**
 * Which document holds the exclusive claim on a settled charge — the value behind
 * `billing_invoices_owner_series_charge_unique`.
 *
 * The sale's FIRST document claims its charge reference; a reissue and a correction claim nothing. Derived
 * on the record rather than at the four call sites deliberately: the model is public surface, so a consumer
 * writing its own document is covered by the same invariant without knowing the column exists.
 *
 * The derived value OVERWRITES anything a caller passed. A column whose value decides whether a duplicate is
 * possible must not be settable — the one caller who set it wrongly would be indistinguishable from the
 * constraint not being there at all.
 */
final class ChargeClaimKeyDeriver
{
    /**
     * The claim key for a document, or null where it claims nothing.
     *
     * @param  ?string  $reference  the settled charge this document is for
     * @param  ?string  $provider  WHOSE reference that is. Part of the key even when empty: two drivers can
     *                             mint the same id for different money, and a key that dropped the provider
     *                             would refuse a second installation's perfectly real document.
     * @param  bool  $coversAPeriod  a document that names a period is recognized BY that period and guarded
     *                               by the period index. Twelve monthly documents of one prepaid term all
     *                               carry the same charge, and claiming it would refuse eleven of them.
     * @param  bool  $isReissue  a reissue restates a document that already holds the claim
     * @param  bool  $isCorrection  a correction belongs to an original that already holds it
     */
    public function keyFor(
        ?string $reference,
        ?string $provider,
        bool $coversAPeriod,
        bool $isReissue,
        bool $isCorrection,
    ): ?string {
        $claims = $reference !== null && $reference !== ''
            && ! $coversAPeriod
            && ! $isReissue
            && ! $isCorrection;

        return $claims ? ($provider ?? '').'|'.$reference : null;
    }
}
