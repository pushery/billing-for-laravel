<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Support\InvoiceNumberSequence;

/**
 * The number an invoice the package raises itself carries.
 *
 * One series for every such invoice: an order's invoice and a receipt from the counter are the seller's invoices
 * alike, and an accountant checks a series for gaps only as a whole. This was a private method on the order
 * issuer until the counter receipt became the second producer, and two copies of a prefix and a padding width
 * stay identical only until somebody changes one of them. See {@see CreditNoteNumber} for the same move.
 *
 * The shape is the one a real document carries: prefix, year, running part. The scope is keyed by prefix and
 * year, so the running part restarts every January and a changed prefix starts a series of its own. Gaps are
 * harmless, since a sequence that skipped a number is not a defect, but a number issued twice cannot be taken
 * back, which is why the sequence locks rather than counts rows.
 */
final readonly class InvoiceNumber
{
    public function __construct(
        private InvoiceNumberSequence $numbers,
        private Repository $config,
    ) {}

    public function next(CarbonInterface $issuedAt): string
    {
        $prefix = $this->config->get('billing.invoices.number_prefix', 'INV');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'INV';
        $year = $issuedAt->format('Y');

        return sprintf('%s-%s-%07d', $prefix, $year, $this->numbers->next("invoice:{$prefix}:{$year}"));
    }
}
