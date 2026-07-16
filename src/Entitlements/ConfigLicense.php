<?php

declare(strict_types=1);

namespace Pushery\Billing\Entitlements;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\License;

/**
 * The config-backed {@see License}: reads the entitlement grants straight from config('license.tiers').
 * It is deliberately stateless — every read is live, so there is no cached grant to purge when an
 * owner's tier changes (the concern a Pennant-backed store has to handle). Anything malformed or
 * unlisted degrades to the safe default: a feature is denied, a limit is uncapped.
 */
final readonly class ConfigLicense implements License
{
    public function __construct(private Repository $config) {}

    public function grants(string $tierKey, string $feature): bool
    {
        $features = $this->section($tierKey, 'features');

        return ($features[$feature] ?? false) === true;
    }

    public function limit(string $tierKey, string $key): ?int
    {
        $limits = $this->section($tierKey, 'limits');
        $limit = $limits[$key] ?? null;

        return is_int($limit) ? $limit : null;
    }

    /**
     * The features/limits map for a tier, or an empty map when the tier or section is absent/malformed.
     *
     * @return array<array-key, mixed>
     */
    private function section(string $tierKey, string $section): array
    {
        $tiers = $this->config->get('license.tiers');
        $tier = is_array($tiers) ? ($tiers[$tierKey] ?? null) : null;
        $map = is_array($tier) ? ($tier[$section] ?? null) : null;

        return is_array($map) ? $map : [];
    }
}
