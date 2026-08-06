<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * Which window a reversal reduces — the one it happened in, or the one it corrects.
 *
 * ## Why this is a setting and not an answer
 *
 * Two tickets specify this counter and they say opposite things. One asks for the period the reversal
 * happened in, on the reasoning that a counter is a record of movements and a movement has a date. The other
 * asks for the original period, warning in as many words that applying the tax-booking rule ("ex nunc", the
 * month it happened) mechanically to the COUNTER is the mistake — a booking and a count answer different
 * questions about the same event.
 *
 * Neither is obviously right and only one may hold, so the choice belonged to whoever owns the reporting
 * obligation. IT HAS BEEN MADE (owner, 2026-07-29): a reversal reduces the period of the document it
 * corrects, AND a crossing that has already happened is final. The two halves ship together because only
 * the pair is safe — see below.
 *
 * ## What hangs on it
 *
 * A creator at 24,960 whose December sale takes them to 25,050 crosses a threshold and is treated as
 * standard-rated from then on. In January they issue eight settlements showing 136.80 of VAT. In February
 * the December sale is refunded.
 *
 * Attributed to the REVERSAL period, the crossing stands and those settlements stay correct — but the
 * year's figure includes turnover that was undone. Attributed to the ORIGINAL period, the year's figure is
 * clean — and unless a crossing that has already happened is explicitly kept, the January VAT becomes
 * retrospectively unlawful across every one of those documents at once.
 *
 * So the second option is only safe together with a rule that a completed crossing is final. That rule is
 * not in this enum and not in the counter — it lives in `SmallBusinessAutoFlip`, which only ever flips
 * forward, and it is pinned by a test that asserts the two figures DISAGREEING: the counter reports the
 * reduced year while the breach still names the transaction and the date it happened on.
 *
 * Choosing the clean year without that rule is the expensive mistake, and it is not hypothetical arithmetic:
 * it is 136.80 of VAT stated across eight documents that a later refund would make retrospectively unlawful,
 * all at once, from an event nobody thought of as a status change.
 */
enum ReversalAttribution: string
{
    /** The reversal reduces the window it happened in. */
    case ReversalPeriod = 'reversal_period';

    /** The reversal reduces the window of the document it corrects. */
    case OriginalPeriod = 'original_period';

    /**
     * The setting this installation runs under, read in ONE place.
     *
     * Both counters ask this question about the same configuration key, and the answer decides which period
     * a reported figure belongs to. Two readers with their own copy of the rule is how a key ends up
     * governing one of them: set to `original_period`, the reporting counter would move a reversal into the
     * quarter it corrects while the threshold counter left it where it happened — one key, two behaviors,
     * and the key's name saying nothing about which.
     *
     * Defaulted to `OriginalPeriod` when no configuration is reachable, so a counter constructed by hand in
     * a test or a script answers the same way the container-resolved one does.
     *
     * That default is a DECISION, not continuity: this sentence used to say "what the package has always
     * done", and the inline comment on the branch below already contradicted it in as many words — the
     * historic behavior was `reversal_period`, and the change was deliberate. Worth stating here rather
     * than only there, because a reader who takes the default for inherited behavior will not think to
     * question it, and it moves a reported figure between quarters.
     *
     * An unreadable value is REFUSED rather than defaulted. Both answers are defensible, so neither is a
     * safe reading of a value somebody mistyped — and the difference is invisible in every figure it
     * produces.
     */
    public static function configured(?Repository $config): self
    {
        $value = $config?->get('billing.tax_counters.reversal_attribution');

        // The decided answer, so a counter built by hand in a test or a script agrees with the
        // container-resolved one. It is NOT "what the package always did" any more — that was
        // `reversal_period`, and the change is deliberate.
        if ($value === null) {
            return self::OriginalPeriod;
        }

        return (is_string($value) ? self::tryFrom($value) : null) ?? throw InvalidBillingConfig::forKey(
            'billing.tax_counters.reversal_attribution',
            "must be 'reversal_period' or 'original_period'",
        );
    }
}
