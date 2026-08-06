<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * Where a sale was decided to have happened, and what that decision rested on.
 *
 * Carries the policy version alongside the answer, because the rule can change and old sales must not be
 * re-read under a new one. A country resolved in March under one reading is what was declared in March;
 * a later change of policy is a change going forward, not a re-interpretation of the past.
 *
 * `country` is null only when nothing could be resolved. That is a refusal rather than a value: there is no
 * fallback to the seller's own country, because a sale silently attributed home is a sale taxed in the
 * wrong place with nothing in the record admitting it.
 */
final readonly class CountryEvidence
{
    public function __construct(
        /** The country the sale is attributed to, or null when the signals could not settle on one. */
        public ?string $country,
        public CountrySignals $signals,
        /** Which reading of the rules produced this — stored so a later change cannot rewrite it. */
        public string $policyVersion,
        /**
         * Whether the buyer has to be asked.
         *
         * Distinct from an unresolved country: this one CAN be settled, by the person who knows. Treating
         * it as a failure would refuse a sale that is merely ambiguous, and treating it as resolved would
         * pick one of two contradicting sources at random.
         */
        public bool $needsBuyerConfirmation = false,
        /**
         * The subdivision the same evidence settled on, where the resolved country has one in scope.
         *
         * Null is the ordinary answer and an honest one: no subdivision was supplied, the country has none
         * in scope, or the sources that named the country named different states. A counter reads that as an
         * explicit `unknown` rather than guessing a state — a guessed state is a nexus threshold measured
         * against the wrong jurisdiction.
         */
        public ?string $subdivision = null,
    ) {}

    /** Whether a sale may proceed on this evidence. */
    public function isSettled(): bool
    {
        return $this->country !== null && ! $this->needsBuyerConfirmation;
    }
}
