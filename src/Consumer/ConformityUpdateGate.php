<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\ConformityUpdatePolicy;
use Pushery\Billing\Exceptions\ConformityWaiverNotPermitted;
use Pushery\Billing\Models\AccessGrant;

/**
 * Whether a conformity update — a defect fix, a security fix, staying compatible — flows to a grant now.
 *
 * ## It never reads the update policy, and that is the whole design
 *
 * `UpdatePolicy` governs ENRICHMENT: new editions and added material, which the creator sells and may
 * withhold. This governs CONFORMITY: what the seller owes afterwards regardless. A frozen grant is a sale
 * whose enrichment stopped, never a sale whose obligations stopped — so nothing in this file looks at
 * `update_policy`, and no value it could hold changes an answer here.
 *
 * ## Off by default, and the off is total
 *
 * Live only where a consumer-rights profile is configured, exactly like the withdrawal gate and for the same
 * reason: this is consumer law, a single seller in the same country owes it just as much, and an operator
 * may run it under a different jurisdiction than their tax. With no profile there is no obligation, no end
 * date is ever stamped, and an install is byte-for-byte what it was.
 *
 * ## On, the on is not switchable
 *
 * With a profile active there is deliberately NO configuration value that turns conformity updates off
 * across the install. That is not an oversight to be fixed by adding one — an operator-wide off switch is
 * precisely the blanket arrangement the law refuses to recognize, and offering it as a setting would let a
 * legal obligation be configured away. The only way an obligation ends early is one grant at a time, against
 * a recorded agreement, and only where the profile says such an agreement can be valid at all.
 */
final readonly class ConformityUpdateGate
{
    public function __construct(
        private Repository $config,
        private ConformityUpdatePolicy $policy,
    ) {}

    /** Whether a consumer-rights profile is active at all. */
    public function isEnforced(): bool
    {
        return $this->config->get('billing.consumer_rights.profile') !== null;
    }

    /**
     * Whether a conformity update flows for this grant at this instant.
     *
     * A grant with no recorded end still gets them. That is the fail-closed direction here — "nobody wrote
     * down when this stops" cannot mean "it has stopped", because the obligation exists whether or not
     * somebody remembered to record its length, and the reading that ends it silently is the one that costs
     * a buyer a security fix.
     */
    public function flowsFor(AccessGrant $grant, ?CarbonInterface $at = null): bool
    {
        if (! $this->isEnforced()) {
            return false;
        }

        if ($grant->conformity_waiver) {
            return false;
        }

        $until = $grant->conformity_update_until;

        return ! $until instanceof CarbonInterface || ! ($at ?? Carbon::now())->greaterThan($until);
    }

    /**
     * The end date to record on a grant being created now, or null when none can be stated.
     *
     * Called on the write side so the period is FROZEN with the sale, like every other fact a document was
     * made under. An operator who later takes advice and configures a longer period does not thereby shorten
     * anybody's existing obligation, and one who shortens it does not reach backwards into sales already
     * made.
     */
    public function updatesUntil(CarbonInterface $acquiredAt): ?CarbonInterface
    {
        return $this->isEnforced() ? $this->policy->updatesUntil($acquiredAt) : null;
    }

    /**
     * Record that this buyer agreed to give up the conformity obligation.
     *
     * Three separate things must hold, and none of them is a config default on its own: a profile must be
     * active (there must be an obligation to give up), that profile must say such an agreement can be valid
     * at all, and there must be a reference to the actual agreement. The third is what keeps this from being
     * settable by configuration — a flag flipped in a file cannot produce a declaration.
     *
     * @throws ConformityWaiverNotPermitted
     */
    public function recordWaiver(AccessGrant $grant, string $declarationReference): AccessGrant
    {
        if (! $this->isEnforced()) {
            throw ConformityWaiverNotPermitted::withoutAProfile();
        }

        if (! $this->policy->waiverPermitted()) {
            throw ConformityWaiverNotPermitted::notAllowedByTheProfile();
        }

        if (trim($declarationReference) === '') {
            throw ConformityWaiverNotPermitted::withoutADeclaration();
        }

        $grant->forceFill([
            'conformity_waiver' => true,
            'conformity_waiver_ref' => $declarationReference,
        ])->save();

        return $grant;
    }
}
