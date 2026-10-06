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

        return self::ceiling($limits[$key] ?? null);
    }

    /**
     * A ceiling as configured: an integer, or a string of digits, which is how env() delivers a number.
     * Read as anything else, a ceiling written as "10" would lift the very limit it was written to set.
     * Every other value is uncapped, as an unlisted key is.
     *
     * @internal shared with ConfigEntitlements, which reads a dimension's ceiling the same way.
     */
    public static function ceiling(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 ? (int) trim($value) : null;
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
