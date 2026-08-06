<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\SuppliesArchetypeRegimes;
use Pushery\Billing\Contracts\SupplyRegimeResolver;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\RegimeNotPermitted;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * The shipped resolver: a configured default, an opt-in list, and whatever the jurisdiction compels.
 *
 * The opt-in list mirrors the seller-posture whitelist beside it, and for the same reason: a regime decides
 * which documents a sale produces and whose turnover it is, so falling into one is never acceptable. A
 * platform that has not said it arranges other people's sales must not start doing so because a product was
 * classified in a way nobody looked at.
 *
 * An archetype may COMPEL a regime, overriding that default — and which archetypes do is a question for the
 * jurisdiction profile, not for this class. It used to be answered here: goods sold between private people
 * were forced to intermediation, because the platform cannot be reselling something it never owned when
 * neither party is in business. The fact is neutral; concluding a REGIME from it is a legal
 * characterization, and one sitting in a neutral core is one jurisdiction's answer wearing the costume of a
 * general one. {@see SuppliesArchetypeRegimes}, which is where it lives now, and why.
 *
 * A platform that has not opted into a regime is still refused rather than quietly switched into it — that
 * guard is the reason the override cannot be dangerous wherever the answer comes from.
 */
final readonly class ConfigSupplyRegimeResolver implements SupplyRegimeResolver
{
    public function __construct(
        private Repository $config,
        private CheckpointRegistry $profiles,
    ) {}

    public function resolveFor(?TaxArchetype $archetype = null): SupplyRegime
    {
        $resolved = $this->compelledBy($archetype) ?? $this->configuredDefault();

        if (! in_array($resolved->value, $this->allowed(), true)) {
            throw RegimeNotPermitted::notAllowed($resolved->value);
        }

        return $resolved;
    }

    /**
     * The regime this archetype compels, if the jurisdiction says one does.
     *
     * A profile that does not answer leaves the configured default standing. That is deliberate and it is
     * the one place this seam differs from its exchange-rate siblings: there a missing profile means no rate
     * exists and inventing one would be the only alternative, so they refuse. Here an answer already exists
     * and an operator wrote it down. Refusing over a rule the jurisdiction never claimed would turn every
     * install without a profile into one that cannot sell.
     */
    private function compelledBy(?TaxArchetype $archetype): ?SupplyRegime
    {
        if (! $archetype instanceof TaxArchetype) {
            return null;
        }

        $profile = $this->profiles->profile();

        return $profile instanceof SuppliesArchetypeRegimes ? $profile->regimeForArchetype($archetype) : null;
    }

    private function configuredDefault(): SupplyRegime
    {
        $value = $this->config->get('billing.marketplace.regime.default', SupplyRegime::CommissionChain->value);

        // An unreadable or unknown value is refused, not defaulted. Silently choosing here would pick which
        // documents every sale produces, on the strength of a typo.
        if (! is_string($value) || SupplyRegime::tryFrom($value) === null) {
            throw RegimeNotPermitted::notAllowed(is_string($value) ? $value : gettype($value));
        }

        return SupplyRegime::from($value);
    }

    /** @return list<string> */
    private function allowed(): array
    {
        $value = $this->config->get('billing.marketplace.regime.allowed', [SupplyRegime::CommissionChain->value]);

        if (! is_array($value)) {
            return [SupplyRegime::CommissionChain->value];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
