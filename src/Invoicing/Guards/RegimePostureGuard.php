<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Guards;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Exceptions\RegimeNotPermitted;

/**
 * A document's supply regime and its seller-of-record posture are one decision seen twice.
 *
 * They are checked at CREATION, the only moment either can be wrong: both columns are frozen afterwards, so
 * a pair that was coherent when the row was written stays coherent forever — and a contradictory one would
 * be immutable from the instant it existed, with no correction available but canceling the document.
 *
 * Creation rather than every save, deliberately. On an update the immutability guard is the one with
 * something to say; refusing a mutation of a frozen column by complaining about the pair would answer a
 * question nobody asked and hide the actual mistake.
 *
 * Takes VALUES rather than the record, following `DocumentRoleGuard` — which is what makes that guard
 * unit-callable and is the whole point of pulling this one out of a model-event closure. The rule is about
 * two enums; a rule that needs a database to be exercised is a rule whose edge cases do not get written.
 */
final class RegimePostureGuard
{
    /** @throws RegimeNotPermitted when the posture has no regime at all, or contradicts this one */
    public function assertCoherent(SupplyRegime $regime, SellerOfRecordPosture $posture): void
    {
        // Naming the merchant is neither the platform reselling nor the platform arranging, so no regime maps
        // to it and no document chain follows. Answered before the mismatch below, because "this posture has
        // no regime at all" is a different mistake from "not THAT regime".
        if (! $this->hasAnyRegime($posture)) {
            throw RegimeNotPermitted::postureHasNoRegime($posture);
        }

        if ($regime->requiredPosture() !== $posture) {
            throw RegimeNotPermitted::contradictsPosture($regime, $posture);
        }
    }

    /** Whether any regime at all produces this posture — asked before comparing it against a specific one. */
    public function hasAnyRegime(SellerOfRecordPosture $posture): bool
    {
        return in_array($posture, array_map(
            static fn (SupplyRegime $case): SellerOfRecordPosture => $case->requiredPosture(),
            SupplyRegime::cases(),
        ), true);
    }
}
