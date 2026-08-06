<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Contracts\RequiresTaxStatusHold;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Preflight\CheckpointRegistry;
use Throwable;

/**
 * Refuses to pay a merchant, or to sell on their behalf, while nobody knows how their supply is taxed.
 *
 * There is no safe guess, and the reason is a symmetry rather than caution. Assume they charge tax normally
 * and a settlement document states tax a small business does not owe — at which point the recipient owes it
 * merely because a document says so, unless they object in time to a document they never asked for.
 * Assume the opposite and the document understates a real liability and forfeits a deduction. The two
 * errors point in opposite directions, so neither default is the conservative one and holding is.
 *
 * BOTH locks, not one. Holding only the payout would be the more dangerous half-fix: buyers' money would
 * keep arriving, each transaction creating an obligation to settle that cannot be settled without a
 * standing — a growing backlog instead of a stop, and every day of it harder to unwind.
 *
 * Fail-closed at every step. A store that cannot be reached, a profile that will not load, a moment no
 * interval covers: all of them mean the standing is unknown, and unknown is the case the hold exists for.
 */
final readonly class CreatorTaxStatusHold
{
    public function __construct(
        private Repository $config,
        private CreatorTaxStatusResolver $statuses,
        private CheckpointRegistry $profiles,
    ) {}

    /** Whether this merchant's own earnings may be paid out at this moment. */
    public function blocksPayout(Model $merchant, ?CarbonImmutable $moment = null): bool
    {
        return $this->held($merchant, $moment) && $this->lockEnabled('blocks_payouts', $moment);
    }

    /** Whether anything may be sold on this merchant's behalf at this moment. */
    public function blocksSales(Model $merchant, ?CarbonImmutable $moment = null): bool
    {
        return $this->held($merchant, $moment) && $this->lockEnabled('blocks_sales', $moment);
    }

    /**
     * The date the hold begins to bite, or null while it does not bite at all.
     *
     * Public because it is the honest answer to "is this configured?", and the go-live checklist asks. A
     * checklist that reported the switches alone would report two active enforcements on an install where
     * nothing is enforced.
     */
    public function enforcedFrom(): ?CarbonImmutable
    {
        $configured = $this->config->get('billing.marketplace.tax_status_hold.enforce_from');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($configured);
        } catch (Throwable) {
            // A date nobody can read is not a date, and the two obvious fallbacks are both wrong. Treating
            // it as "now" refuses every routed sale on a typo; treating it as null disables a tax control
            // silently. So it is a refusal that names the key -- loud, and impossible to mistake for either.
            throw InvalidBillingConfig::unreadableHoldEnforcementDate($configured);
        }
    }

    /**
     * The standing itself, resolved defensively.
     *
     * Any failure to determine it answers "unestablished". A resolver that threw would otherwise leave the
     * caller to decide what an error means, and the tempting answer at a call site is "carry on".
     */
    public function statusFor(Model $merchant, ?CarbonImmutable $moment = null): CreatorTaxStatus
    {
        try {
            return $this->statuses->statusAt($merchant, $moment ?? CarbonImmutable::now());
        } catch (Throwable) {
            return CreatorTaxStatus::Unclarified;
        }
    }

    private function held(Model $merchant, ?CarbonImmutable $moment): bool
    {
        return $this->statusFor($merchant, $moment)->blocksSelling();
    }

    /**
     * Whether a lock is in force.
     *
     * The switches are honored only where no active profile demands the hold. Under one that does they are
     * inert — and deliberately so: turning payouts back on there would be choosing a default tax standing
     * for people whose standing nobody knows, quietly, by config. There is correspondingly no key that
     * names a default standing; a consumer who wants no hold changes the profile, rather than inventing an
     * answer to a question that was not asked.
     */
    private function lockEnabled(string $key, ?CarbonImmutable $moment): bool
    {
        if ($this->profiles->profile() instanceof RequiresTaxStatusHold) {
            return true;
        }

        // The rollout date, checked before the switches and only outside such a profile. The switches say
        // WHAT is held; this says from when. With no date the answer is "not yet" -- deliberately, because
        // the day this starts is the day every merchant who has not declared stops selling, and on an
        // established marketplace that is a decision with a date on it rather than a flag flip.
        $from = $this->enforcedFrom();

        if (! $from instanceof CarbonImmutable || ($moment ?? CarbonImmutable::now())->lessThan($from)) {
            return false;
        }

        return (bool) $this->config->get('billing.marketplace.tax_status_hold.'.$key, true);
    }
}
