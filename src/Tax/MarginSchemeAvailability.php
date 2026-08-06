<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxationBasis;
use RuntimeException;

/**
 * Whether a sale may be taxed on the margin at all — the invariant the whole margin analysis rests on.
 *
 * ## Where the platform is the supplier, the margin scheme cannot apply
 *
 * The scheme is available to a reseller for goods that were SUPPLIED TO THEM without deductible tax. Where a
 * platform is treated as the supplier of goods it never bought, the leg that would have to be that supply is
 * either outside the union entirely or exempt — and an exempt inbound leg is not one of the acquisitions the
 * scheme lists. So the two are mutually exclusive, and not as a matter of preference.
 *
 * It is enforced rather than documented because the failure is invisible: a margin-taxed document states no
 * tax at all, so a platform that reached this state would issue documents that look deliberately silent
 * while owing tax on the full price. Nothing about them reads as wrong.
 *
 * ## Being privately acquired is NOT the disqualifying fact
 *
 * This is the part most likely to be implemented backwards, and it was carried as a disputed point for
 * months before the criterion was read properly. The statute does not ask whether an item was once private.
 * It asks whether the item was supplied to the reseller BY SOMEBODY, FOR CONSIDERATION.
 *
 * The case that matters here — a person who tips into trader status and then sells their own belongings,
 * bought years earlier as a consumer — fails that test outright: there is no counterparty and no
 * consideration, so there was no supply to the reseller at all. Not a contested exclusion; simply not the
 * situation the scheme describes.
 *
 * Which also means the opposite must not be inferred: goods bought FROM a private person, for a price, ARE
 * within the scheme. That is the ordinary second-hand purchase the scheme exists for, and a rule keyed to
 * "was it private" would wrongly exclude exactly it.
 */
final readonly class MarginSchemeAvailability
{
    /**
     * Whether this sale may be taxed on the margin.
     *
     * @param  bool  $acquiredBySupplyForConsideration  whether somebody supplied the goods to the seller for a
     *                                                  price — not whether the goods were once privately owned
     */
    public function permits(
        TaxationBasis $basis,
        SellerOfRecordPosture $posture,
        bool $acquiredBySupplyForConsideration,
    ): bool {
        if (! $basis->taxesMarginOnly()) {
            return true;
        }

        return $posture !== SellerOfRecordPosture::PlatformDeemedSupplier
            && $acquiredBySupplyForConsideration;
    }

    /**
     * Refuse a sale that cannot be taxed on the margin, naming which half failed.
     *
     * Two messages rather than one, because the two failures need different actions: a posture clash is a
     * configuration or routing question, while a missing acquisition is a fact about the goods that somebody
     * has to supply. A single message would send both readers to the wrong place.
     *
     * @throws RuntimeException
     */
    public function assertPermitted(
        TaxationBasis $basis,
        SellerOfRecordPosture $posture,
        bool $acquiredBySupplyForConsideration,
    ): void {
        if ($this->permits($basis, $posture, $acquiredBySupplyForConsideration)) {
            return;
        }

        if ($posture === SellerOfRecordPosture::PlatformDeemedSupplier) {
            throw new RuntimeException(
                'A sale cannot be taxed on the margin while the platform is treated as the supplier of the '
                .'goods: the scheme is available to a reseller for goods supplied TO them without deductible '
                .'tax, and the leg that would have to be that supply is either outside the union or exempt. '
                .'The document would state no tax while the full price was owed, and nothing about it would '
                .'read as wrong.'
            );
        }

        throw new RuntimeException(
            'A sale cannot be taxed on the margin unless the goods were supplied to the seller by somebody, '
            .'for a price. Being previously owned privately is NOT the test — goods bought from a private '
            .'person for a price are exactly what the scheme is for. What fails here is that there was no '
            .'supply to the seller at all: no counterparty, no consideration, nothing acquired.'
        );
    }
}
