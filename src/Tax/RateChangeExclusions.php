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

    /**
     * Whether a dry run has to happen before this change is applied.
     *
     * Always. The inherited guidance makes it mandatory rather than advisable, and the reason is the same
     * one behind the exclusion list: the cost of finding out afterwards is not symmetric with the cost of
     * looking first.
     */
    public static function dryRunRequired(): bool
    {
        return true;
    }
}
