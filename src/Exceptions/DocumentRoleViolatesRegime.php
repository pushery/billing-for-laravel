<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\SupplyRegime;
use RuntimeException;

/**
 * A document was about to be written whose ROLE does not belong to the sale's frozen supply regime.
 *
 * The cardinal case is a commission invoice under the commission chain (regime K). There the platform's
 * margin is the difference between the two fictional supplies' tax bases, not a supply of its own
 * (Abschn. 3.15 Abs. 1 UStAE); a commission invoice would bill for a supply that does not exist for VAT,
 * costing the creator their input-tax deduction and leaving the platform a § 14c Abs. 2 liability. So the
 * document is refused at creation rather than corrected after the fact — a written one is already the harm.
 *
 * Keyed on the regime, not the posture: the regime is the posture's locked twin (the regime↔posture guard
 * holds them equal), and it is the field a self-billed document can carry without contradicting the seller
 * it names — a self-billed invoice names the creator as seller, which the posture guard reads as "not the
 * platform", so only the regime is a clean key for the role check.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DocumentRoleViolatesRegime extends RuntimeException
{
    public static function roleNotPermitted(SupplyRegime $regime, DocumentSeries $series): self
    {
        return new self(
            "A [{$series->value}] document does not belong to the supply regime [{$regime->value}]. Each regime "
            .'produces its own document roles — the commission chain settles the creator by self-billed '
            .'invoice or settlement note and receipts the buyer, intermediation issues a real commission '
            .'invoice for the platform\'s own fee — and a role from the other regime describes a transaction '
            .'that did not happen. Cancel and re-issue in the correct role rather than writing this one.'
        );
    }

    public static function commissionInvoiceInCommissionChain(): self
    {
        return new self(
            'A commission invoice cannot exist in the commission chain (regime K). The platform\'s margin is the '
            .'difference between the two fictional supplies\' tax bases, not a supply of its own — a commission '
            .'invoice would bill a VAT-nonexistent supply, reverse the creator\'s input tax, and leave a '
            .'§ 14c Abs. 2 liability here. The chain settles by self-billed invoice or settlement note; there '
            .'is no third document.'
        );
    }
}
