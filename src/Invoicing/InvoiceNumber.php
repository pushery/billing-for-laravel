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
 * alike, and an accountant checks a series for gaps only as a whole. Both producers draw through this class,
 * because two copies of a prefix and a padding width stay identical only until somebody changes one of them. See
 * {@see CreditNoteNumber} for the same move.
 *
 * The shape is the one a real document carries: prefix, year, running part. The scope is keyed by prefix and
 * year, so the running part restarts every January and a changed prefix starts a series of its own. A gap does
 * not make an invoice invalid, a repeated number does, and a number issued twice cannot be taken back, which is
 * why the sequence locks rather than counts rows. The order issuer still avoids a gap from a failed document by
 * drawing the number in the transaction that writes it.
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
