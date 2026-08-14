<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Guards;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Exceptions\SellerContradictsPosture;
use Pushery\Billing\Invoicing\Party;

/**
 * The seller a document NAMES must agree with the posture it carries: the deemed supplier is the platform,
 * anyone else is the merchant.
 *
 * This is the WHO half of "a document must match its sale"; `DocumentRoleGuard` is the ROLE half. Checked at
 * creation and independent of the regime — a document may carry a posture and a seller without a regime —
 * and skipped entirely where no seller was snapshotted, so a single-seller row is untouched.
 *
 * ## Both sides go through `Party`, and that is not decoration
 *
 * The snapshot on the document was written through `Party`, where an absent name is the empty string; raw
 * configuration leaves it null. Comparing the two directly makes `''` and `null` different parties, and an
 * installation with no company configured could then never satisfy this check — every commission-chain
 * document it issued would be refused for naming "a different party" than the one it had just snapshotted.
 *
 * That was invisible for as long as nothing wrote `seller_posture`: the guard returned early and its own
 * comparison never ran. Arming the column is what surfaced it, which is the argument for arming guards
 * rather than leaving them correct-looking and unreachable.
 */
final class SellerMatchesPostureGuard
{
    /**
     * @param  array<array-key, mixed>  $seller  the party snapshotted on the document — decoded from a JSON
     *                                           column, so its key shape is whatever was written
     * @param  array<array-key, mixed>  $company  the platform's own configured party, in any shape at all;
     *                                            `Party::fromArray()` is what makes it comparable
     *
     * @throws SellerContradictsPosture when the named seller is not who the posture says it must be
     */
    public function assertMatches(SellerOfRecordPosture $posture, array $seller, array $company): void
    {
        if ($posture === SellerOfRecordPosture::PlatformDeemedSupplier) {
            if (! $this->isPlatform($seller, $company)) {
                throw SellerContradictsPosture::platformMustSell($posture);
            }

            return;
        }

        if ($this->isPlatform($seller, $company)) {
            throw SellerContradictsPosture::merchantMustSell($posture);
        }
    }

    /**
     * Whether the named seller IS the platform company — identity by legal name plus tax id.
     *
     * @param  array<array-key, mixed>  $seller
     * @param  array<array-key, mixed>  $company
     */
    public function isPlatform(array $seller, array $company): bool
    {
        $normalized = Party::fromArray($company)->toArray();

        return ($seller['name'] ?? null) === $normalized['name']
            && ($seller['vat_id'] ?? null) === $normalized['vat_id'];
    }
}
