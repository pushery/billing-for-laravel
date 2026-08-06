<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How much human attention one proposed rate change deserves.
 *
 * ## Why this is graded and not a switch
 *
 * A seam that demands approval for **every** change gets switched off. Not through negligence: the third
 * confirmation, for a digest change in a country nobody has ever invoiced, spends exactly the attention the
 * first one needed for a real increase. Ask about everything and you are read about nothing.
 *
 * So the grading is the safety feature, not a convenience. It exists to keep the one prompt that matters
 * worth reading.
 */
enum RateChangeClass: string
{
    /**
     * A confirmed increase — scheduled automatically, announced at once, with a window to veto it.
     *
     * The default is APPLY, and the asymmetry is the argument. Failing to apply a real increase is the more
     * expensive mistake, and it is precisely the mistake that started all of this: undercharging is paid by
     * the platform and discovered at an audit. Overcharging is noticed by the buyer the same day and is
     * correctable.
     */
    case ScheduledIncrease = 'scheduled_increase';

    /**
     * A decrease, a country appearing or disappearing, a jump past the plausibility bound, or a
     * disagreement between the official source and the second opinion. **Holds.**
     *
     * A decrease that is not real undercharges — the same direction as the incident. And a country that
     * vanishes from a response is almost always a parsing error, almost never a change in law.
     */
    case HeldForApproval = 'held_for_approval';

    /**
     * A change to a country this installation has never invoiced. Recorded, carried along, no prompt.
     *
     * This is the case that makes the other two readable. It is not "less important" in the abstract — it
     * is genuinely of no consequence to this operator today, and spending a click on it is what teaches
     * people to click without reading.
     */
    case RecordedOnly = 'recorded_only';
}
