<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A settlement records that it settled a charge, and none of its lines mentions that charge.
 *
 * ## Why this is loud rather than answered from the header
 *
 * A collective settlement carries a month. Its header states no commission, no rate and no archetype,
 * because a month has one of none of those — and a correction of one transaction out of it reads exactly
 * those off the line the transaction became.
 *
 * So when the charge-side link points at such a document and no line names the charge, the two records
 * disagree with each other. Falling back to the header there is not a graceful degradation: it computes the
 * correction at a zero commission and a zero rate, and produces a document that looks complete and is wrong
 * by both. That is the error class this package refuses to emit, and the one nobody notices until a
 * reconciliation months later.
 *
 * A per-transaction settlement is NOT this case. Its single line names no charge because the header is the
 * line, and its readers take the header as they always have — so this is raised only where a document
 * covers a period.
 *
 * Concatenation here assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped and not what a test asserts — the same note its sibling
 * {@see InvalidDatevBatch} carries, for the same reason.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SettlementLineNotFound extends RuntimeException
{
    public static function forCharge(string $document, string $provider, string $reference): self
    {
        return new self(
            'The settlement "'.$document.'" covers a period and records that it settled the charge '
            .$provider.'/'.$reference.', and no line of it answers for that charge — either none names it, '
            .'or the one that does was written without the commission rate, the tax rate or the fan gross. '
            .'The correction is refused rather than computed from the header, which states none of those '
            .'for a period: doing so would book the reversal at zero of whichever is missing, and each one '
            .'fails differently while looking equally complete. Re-run the collective settlement for that '
            .'month so its lines carry those figures, or correct the charge-to-document link.'
        );
    }
}
