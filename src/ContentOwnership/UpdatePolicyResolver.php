<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\UpdatePolicyCatalog;
use Pushery\Billing\Enums\UpdatePolicy;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Models\AccessGrant;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\VersionResolution;

/**
 * The enrichment-update rules: which policy a sale is made under, and what that policy resolves to today.
 *
 * ## Two jobs, at two different times
 *
 * `policyFor()` runs ONCE, when a grant is created, and its answer is frozen onto the row. `resolutionFor()`
 * runs on every read and can answer differently each time. Keeping them in one class is deliberate — they
 * are the two halves of one rule set, and splitting them is how the write side and the read side start
 * disagreeing about what "windowed" means.
 *
 * ## Enrichment only, and the line is a legal one
 *
 * These policies govern NEW content, and the creator chooses them. Conformity updates — a defect fix, a
 * security patch — are mandatory, cannot be waived by a product setting, and are a separate strand. So there
 * is deliberately no fifth policy here: `Frozen` freezes what a buyer is ENTITLED to and says nothing about
 * what the seller still owes. A fifth case would be a product setting that reads as waiving an obligation
 * that cannot be waived.
 */
final readonly class UpdatePolicyResolver
{
    public function __construct(
        private UpdatePolicyCatalog $catalog,
        private Repository $config,
    ) {}

    /**
     * The policy a sale of this work is made under: the work's own, else the merchant's default, else the
     * package's configured one.
     *
     * The order is the point. A creator who set a policy on one book means it for that book; a merchant
     * default is what they meant for everything they did not think about; the config default is what the
     * install means for a merchant who has never expressed a preference. Reversing any two of those would
     * quietly overrule somebody's explicit decision with somebody else's blanket one.
     */
    public function policyFor(ContentReference $content, ?MerchantScope $merchant = null): UpdatePolicy
    {
        return $this->catalog->policyForContent($content)
            ?? $this->catalog->policyForMerchant($merchant)
            ?? $this->configuredDefault();
    }

    /**
     * What the grant resolves to at this moment.
     *
     * The windowed case is the only one that moves with the clock, and it moves LAZILY: while the window is
     * open the buyer simply gets the newest version, and the boundary only starts applying once the window
     * has closed. Nothing is written when a window lapses — a job that had to stamp every expired grant
     * would be a job that can be late, and a late job here means handing somebody a version they no longer
     * paid for.
     */
    public function resolutionFor(AccessGrant $grant, CarbonInterface $at): VersionResolution
    {
        return match ($grant->update_policy) {
            UpdatePolicy::Latest => VersionResolution::latest(),
            UpdatePolicy::LatestWithRevisions => VersionResolution::latest(includesEarlierVersions: true),
            UpdatePolicy::Windowed => $this->windowedResolution($grant, $at),
            UpdatePolicy::Frozen => $this->frozenResolution($grant),
        };
    }

    /**
     * Extend a windowed grant's update window — what a top-up purchase buys.
     *
     * Only ever forward. A shorter date would take away updates somebody has already paid for, and there is
     * no purchase that means that, so a request to shorten leaves the row alone rather than trusting the
     * caller. Extending re-opens the window by construction: the resolution goes back to `Latest` the moment
     * the new end is in the future, because nothing was ever written down that has to be undone.
     */
    public function extendWindow(AccessGrant $grant, CarbonInterface $until): AccessGrant
    {
        $current = $grant->update_window_ends_at;

        if ($current instanceof CarbonInterface && ! $until->greaterThan($current)) {
            return $grant;
        }

        $grant->forceFill(['update_window_ends_at' => $until])->save();

        return $grant;
    }

    private function windowedResolution(AccessGrant $grant, CarbonInterface $at): VersionResolution
    {
        $end = $grant->update_window_ends_at;

        // A windowed promise with no window recorded is a broken row, and the safe reading of one is the
        // moment of purchase: the buyer keeps what existed when they bought, which is the least any policy
        // would have given them. Floating it forward would hand out updates nobody sold.
        if (! $end instanceof CarbonInterface) {
            return VersionResolution::boundedAt($grant->acquired_at);
        }

        return $at->greaterThan($end)
            ? VersionResolution::boundedAt($end)
            : VersionResolution::latest();
    }

    private function frozenResolution(AccessGrant $grant): VersionResolution
    {
        $pin = $grant->version_pin_ref;

        // Same fail-closed reading as the missing window, for the same reason: a frozen grant whose pin was
        // never written must not silently become "always the newest", which is the exact opposite of what it
        // was sold as.
        return $pin === null
            ? VersionResolution::boundedAt($grant->acquired_at)
            : VersionResolution::pinnedTo($pin);
    }

    /**
     * The install's default, refused rather than coerced when it is not one of the four.
     *
     * A typo that fell back to `latest` would be a silent promise of free updates forever on every sale the
     * install ever makes — the most expensive possible reading of a misspelt word, and one nothing else
     * would ever surface.
     */
    private function configuredDefault(): UpdatePolicy
    {
        $configured = $this->config->get('billing.content_ownership.default_update_policy');

        if (! is_string($configured) || UpdatePolicy::tryFrom($configured) === null) {
            throw InvalidBillingConfig::forKey(
                'billing.content_ownership.default_update_policy',
                'must be one of '.implode(', ', array_map(static fn (UpdatePolicy $p): string => $p->value, UpdatePolicy::cases()))
            );
        }

        return UpdatePolicy::from($configured);
    }
}
