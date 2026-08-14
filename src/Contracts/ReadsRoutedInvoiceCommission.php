<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\RoutedInvoiceCommission;

/**
 * Reads back what a provider withheld on a routed subscription invoice.
 *
 * ## Why this contract exists at all
 *
 * Until now no effect in `src/Webhooks/Effects/` reached the provider, and that boundary was worth keeping.
 * The routed subscription lane is what crosses it: the commission on a cycle exists only at the provider,
 * because the lane prices with a rate and the provider applies it per invoice.
 *
 * So the boundary is crossed through a seam rather than dissolved. The effect asks a question in the
 * package's own vocabulary — "what was withheld on this invoice, and for whom" — and never learns whose
 * API answered it. A driver that needs three calls to answer and one that needs none look identical from
 * the effect's side, which is the property that makes the effect testable without a provider at all.
 *
 * ## Null is an answer, and it is the one that must stay loud
 *
 * Null means the invoice was not routed, or the provider could not say. The caller records nothing and the
 * event is NOT quietly marked done: the whole reason this lane reads rather than computes is that a failed
 * read is a loud event, while a wrong computation is a plausible number nobody questions. Swallowing the
 * null here would give away that advantage exactly where it is needed.
 */
interface ReadsRoutedInvoiceCommission
{
    /**
     * What the provider withheld on this invoice, or null when it was not routed.
     *
     * @param  string  $invoiceReference  the provider's own invoice id, as the paid-invoice event names it
     */
    public function forInvoice(string $invoiceReference): ?RoutedInvoiceCommission;
}
