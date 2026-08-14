<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One thing wrong with a period's reporting data, named specifically enough to be worked on.
 *
 * ## Why a finding rather than an exception
 *
 * The parts this checks already refuse: {@see SellerReportingLine::reportable()} throws on a line nobody
 * classified, and it is right to. But a refusal answers ONE question and stops — so an operator preparing
 * a filing learns about the first problem, fixes it, runs again, and learns about the second. With a
 * deadline in January and a hundred sellers, that is the whole month.
 *
 * A finding is the same fact with the stopping taken out: every rule runs, every problem is named, and the
 * run refuses ONCE at the end with the full list.
 *
 * ## The identity, and why the message is not part of it
 *
 * `rule` and `subject` are what makes two findings the same finding. The detail is not: it carries the
 * figures, and figures move between runs. If the message were part of the identity, acknowledging a
 * mismatch of 1.19 would leave the same mismatch acknowledged at 1.20 unnoticed — or, the other way
 * round, an acknowledgement would evaporate because a number was restated.
 *
 * The identity deliberately carries NO period. The store adds one, because an acknowledgement that
 * outlived its period would be a switched-off rule with a timestamp in front of it.
 */
final readonly class PlausibilityFinding
{
    /**
     * @param  string  $rule  the rule that raised it — stable across releases, because it is half of an
     *                        acknowledgement's key and a renamed rule would silently unacknowledge itself
     * @param  string  $subject  what it is about: a seller as `type#id`, or an empty string when the
     *                           finding is about the period as a whole rather than about anybody in it
     * @param  string  $detail  what an operator needs in order to act, in their own terms
     */
    public function __construct(
        public string $rule,
        public string $subject,
        public string $detail,
    ) {}

    /** The identity two runs agree on, and the half of an acknowledgement's key that is not the period. */
    public function key(): string
    {
        return $this->subject === '' ? $this->rule : $this->rule.'|'.$this->subject;
    }
}
