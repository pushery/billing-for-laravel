<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

/**
 * What an automated rate change never touches.
 *
 * ## This list is inherited, not invented
 *
 * It comes from the most mature documented treatment of a bulk rate change, and that documentation is at its
 * core an **exclusion list**: posted documents, documents with posted prepayments, partially delivered
 * orders, credit notes, returns. All skipped, all reviewed by hand, and a dry run is mandatory.
 *
 * It is adopted wholesale because it was expensive to learn. The common denominator is worth stating in one
 * line, because it is what makes the list memorable rather than arbitrary:
 *
 * > **Anything a document already exists for.**
 *
 * A rate change applies to future tax points. A document already issued carries the rate it was issued
 * under — that is what freezing it was for — and if that rate was wrong it is **corrected**, visibly, not
 * re-saddled quietly. Re-saddling would leave a corrected-looking document that no correction exists for.
 *
 * ## Why this is a class and not a comment
 *
 * Because "the automation does not do that" is a claim, and claims that live only in prose stop being true
 * without anybody noticing. Here it can be asserted.
 *
 * ## The dry run is REQUIRED of the caller — this package does not enforce it
 *
 * The inherited guidance makes a dry run mandatory before a bulk rate change is applied, and the reason is
 * the same asymmetry the exclusion list rests on: finding out afterwards costs more than looking first.
 *
 * **That requirement is addressed to whoever runs the change, and this package is not that.** It ships no
 * bulk rate-change command and deliberately does not: changing rates across a corpus is an operator act
 * with a signature behind it, not a package feature.
 *
 * This used to be stated as a method — `dryRunRequired()`, returning `true` unconditionally, called by
 * nobody. It read like an enforced protection and was none: there was no point at which the answer
 * prevented anything. A reader would reasonably conclude the package guarded the dry run, and shipped
 * prose that promises a protection the reader does not have is worse than silence. So it says what it is:
 * an instruction to the caller, in the one place the caller already reads to learn what the automation
 * must skip.
 *
 * `mayTouch()` stays a method because it is the other kind of thing entirely — it ANSWERS a question,
 * about a specific record kind, and a caller cannot work out the answer without it.
 */
final readonly class RateChangeExclusions
{
    /**
     * The record kinds an automated rate change must skip.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            'issued_invoice',
            'invoice_with_posted_prepayment',
            'partially_delivered_order',
            'credit_note',
            'return',
        ];
    }

    /** Whether an automated rate change may touch a record of this kind. */
    public static function mayTouch(string $kind): bool
    {
        return ! in_array($kind, self::kinds(), true);
    }
}
