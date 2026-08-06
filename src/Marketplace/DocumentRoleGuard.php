<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Exceptions\DocumentRoleViolatesRegime;

/**
 * Which document roles a supply regime may produce — the guard that keeps the commission chain from ever
 * emitting a commission invoice, and intermediation from ever settling a creator.
 *
 * The commission chain (regime K) receipts the buyer, self-bills the creator, or settles a private party;
 * it never issues a commission invoice, because the platform's margin is not a supply of its own. Genuine
 * intermediation (regime V) does the opposite: it issues a commission invoice for the platform's own fee and
 * settles no creator. A correction draws from its own series but belongs wherever the original it corrects
 * belongs, so it inherits the original's permission.
 *
 * This is the ROLE half of the belief that a document must match its sale; the WHO half — whether a receipt
 * names the platform or the merchant — is the seller-vs-posture guard on the record itself. Kept pure and
 * regime-keyed so it can sit inside the record's creation guard, where no second caller (a job, a console
 * command, consumer code) can route around it. A jurisdiction whose roles differ binds its own profile; the
 * mapping here is the structural default, not a national one.
 */
final class DocumentRoleGuard
{
    public function permits(SupplyRegime $regime, DocumentSeries $series): bool
    {
        // A correction is the same role as what it corrects, so collapse to the original before deciding.
        $role = $series->corrects() ?? $series;

        return match ($regime) {
            SupplyRegime::CommissionChain => in_array($role, [
                DocumentSeries::BuyerReceipt,
                DocumentSeries::SelfBilledInvoice,
                DocumentSeries::SettlementNote,
            ], true),
            SupplyRegime::Intermediation => $role === DocumentSeries::CommissionInvoice,
        };
    }

    public function assertPermitted(SupplyRegime $regime, DocumentSeries $series): void
    {
        if ($this->permits($regime, $series)) {
            return;
        }

        // The commission invoice in the commission chain is the named red line, so it gets its own message;
        // every other mismatch shares the general one.
        $role = $series->corrects() ?? $series;

        if ($regime === SupplyRegime::CommissionChain && $role === DocumentSeries::CommissionInvoice) {
            throw DocumentRoleViolatesRegime::commissionInvoiceInCommissionChain();
        }

        throw DocumentRoleViolatesRegime::roleNotPermitted($regime, $series);
    }
}
