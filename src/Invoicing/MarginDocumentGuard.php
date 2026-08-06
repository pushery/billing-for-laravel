<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Contracts\SuppliesMarginSchemeWording;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Preflight\CheckpointRegistry;
use RuntimeException;

/**
 * Stops a margin-taxed document from stating tax, and supplies the wording that replaces it.
 *
 * ## Why stating the tax is worse than merely wrong
 *
 * On an ordinary document a wrong tax figure is a wrong figure. Here it is an additional debt: the seller
 * owes the tax on the margin AND the amount they wrote down, and the buyer cannot deduct either — so the
 * money leaves and helps nobody. The failure has no symptom at issue time; it surfaces at an audit, by which
 * point every document of that kind carries it.
 *
 * Naming the rate counts as stating the tax. That is the part most likely to be got wrong by somebody
 * reasoning from first principles, because a rate with no amount beside it looks harmless.
 */
final readonly class MarginDocumentGuard
{
    public function __construct(private CheckpointRegistry $profiles) {}

    /** @throws RuntimeException when a margin-taxed document carries a tax amount */
    public function assertNoStatedTax(InvoiceRecord $invoice): void
    {
        if (($invoice->tax_minor ?? 0) === 0) {
            return;
        }

        throw new RuntimeException(
            'A margin-taxed document must not state a tax amount. Stating one does not merely misreport it: '
            .'the seller owes the tax on the margin AND the amount written down, while the buyer can deduct '
            .'neither. Put the margin tax in the seller\'s own books and leave the document silent.'
        );
    }

    /** The translation key of the prescribed wording, or null where the jurisdiction supplies none. */
    public function wordingKey(): ?string
    {
        $profile = $this->profiles->profile();

        return $profile instanceof SuppliesMarginSchemeWording ? $profile->marginSchemeNote() : null;
    }
}
