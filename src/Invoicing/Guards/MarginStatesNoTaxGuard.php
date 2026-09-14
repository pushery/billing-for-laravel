<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing\Guards;

use Pushery\Billing\Models\InvoiceRecord;
use RuntimeException;

/**
 * Refuses a margin-taxed document that states a tax amount.
 *
 * ## Why stating the tax is worse than merely wrong
 *
 * On an ordinary document a wrong tax figure is a wrong figure. Here it is an additional debt: the seller owes
 * the tax on the margin AND the amount written down, and the buyer can deduct neither. The failure has no
 * symptom when the document is issued. It surfaces at an audit, by which point every document of that kind
 * carries it.
 *
 * ## Why at creation, and not only when the document is rendered
 *
 * The renderer refused such a document long before this guard existed, and the refusal came too late to
 * help. By the time a document is rendered it has been written, and its tax columns are frozen from the
 * moment it exists. A margin document stating a tax cannot be corrected into a right one afterwards; it can
 * only be canceled.
 *
 * It matters more than it used to, because the package issues no margin-taxed document itself. Every one is
 * written by consumer code, and the table is the one place each of them passes through. The renderer asks
 * this same guard, so the two refusals cannot drift apart.
 */
final class MarginStatesNoTaxGuard
{
    /** @throws RuntimeException when a margin-taxed document carries a tax amount */
    public function assertStatesNoTax(InvoiceRecord $invoice): void
    {
        if (! ($invoice->taxation_basis?->taxesMarginOnly() ?? false)) {
            return;
        }

        if (($invoice->tax_minor ?? 0) === 0) {
            return;
        }

        throw new RuntimeException(
            'A margin-taxed document must not state a tax amount. Stating one does not merely misreport it: '
            .'the seller owes the tax on the margin AND the amount written down, while the buyer can deduct '
            .'neither. Put the margin tax in the seller\'s own books and leave the document silent.'
        );
    }
}
