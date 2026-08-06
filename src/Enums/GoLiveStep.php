<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The ordered spine of the go-live checklist: the stages an operator works through before the marketplace
 * switch may be flipped.
 *
 * The spine is deliberately EMPTY of jurisdiction. It says only that contract terms come before authority
 * registrations, registrations before configuration, and configuration before an additional payout rail —
 * an ordering that holds wherever the package runs, because each stage is a precondition of the next in
 * fact, not in law. What each stage CONTAINS is contributed by a jurisdiction profile and by the consumer;
 * the core never names a statute or an authority.
 *
 * There is no case for the switch itself. The switch is not a step the checklist checks, it is the action
 * the checklist gates: a green preflight means "you may activate", and a step that could only pass after
 * activation would make the report unreachable forever.
 */
enum GoLiveStep: string
{
    case Terms = 'terms';
    case Registrations = 'registrations';
    case Configuration = 'configuration';
    case DeferredPayoutRail = 'deferred_payout_rail';

    /** The position in the checklist, 1-based — a later step is unreachable while an earlier one is open. */
    public function order(): int
    {
        return match ($this) {
            self::Terms => 1,
            self::Registrations => 2,
            self::Configuration => 3,
            self::DeferredPayoutRail => 4,
        };
    }

    /** A short operator-facing heading. Neutral: it describes the stage, never the jurisdiction's content. */
    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Contract terms published',
            self::Registrations => 'Authority registrations started',
            self::Configuration => 'Configuration set',
            self::DeferredPayoutRail => 'Deferred-payout rail released',
        };
    }

    /**
     * Every step in checklist order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $steps = self::cases();

        usort($steps, static fn (self $a, self $b): int => $a->order() <=> $b->order());

        return $steps;
    }
}
