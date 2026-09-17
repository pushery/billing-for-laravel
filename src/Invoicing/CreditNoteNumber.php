<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Pushery\Billing\Support\InvoiceNumberSequence;

/**
 * The number a credit note against a locally raised invoice carries.
 *
 * ## One series, and the reason is the reader rather than the code
 *
 * Every document that reduces a local invoice draws from this one sequence — a refund's note and a § 17
 * reduction of consideration alike. Two series would mean an accountant reading the credit notes of one
 * year sees two numbering streams for what is, to them, one class of document, and has to know which
 * producer wrote which before they can tell whether either is gapless. Gaplessness is the property the
 * series exists for, and it is only checkable per series.
 *
 * ## Why this is a class at all
 *
 * It was a private method on the webhook effect that issues a refund's note, and the second producer was
 * about to copy four lines of it. A prefix and a padding width duplicated in two places is the kind of
 * pair that stays identical right up until somebody changes one — and the drift would be invisible, since
 * both halves keep producing perfectly valid numbers. Held here, there is one place to change and no way
 * for the two to disagree.
 *
 * The scope is keyed by YEAR, so the counter restarts every January while the prefix carries the year —
 * the shape a German document series is normally read in.
 */
final readonly class CreditNoteNumber
{
    public function __construct(private InvoiceNumberSequence $numbers) {}

    public function next(CarbonInterface $issuedAt): string
    {
        $year = $issuedAt->format('Y');

        return sprintf('CN-%s-%07d', $year, $this->numbers->next("credit_note:{$year}"));
    }
}
