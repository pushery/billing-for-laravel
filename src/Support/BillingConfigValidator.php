<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * Fail-loud validation of the billing configuration — a small, directly-testable unit (not buried in the
 * service provider) that the boot process runs so a misconfiguration surfaces at boot with a clear message
 * instead of silently mis-tiering a customer or breaking a screen mid-request.
 *
 * Every check is a no-op on the shipped default (empty tiers/dimensions, ascending dunning, owner 'user'),
 * so a fresh, unconfigured install boots clean; the checks only bite a configured app that contradicts
 * itself.
 */
final readonly class BillingConfigValidator
{
    public function __construct(private Repository $config) {}

    public function validate(): void
    {
        $this->validateOwner();
        $this->validateTiers();
        $this->validateDimensionWarnThresholds();
        $this->validateDunningAscending();
    }

    private function validateOwner(): void
    {
        $owner = $this->config->get('billing.owner', 'user');

        if (! in_array($owner, ['user', 'team'], true)) {
            throw InvalidBillingConfig::ownerMode(is_string($owner) ? $owner : gettype($owner));
        }
    }

    private function validateTiers(): void
    {
        $tiers = $this->config->get('billing.tiers', []);

        if (! is_array($tiers) || $tiers === []) {
            return; // an unconfigured install has no tiers to validate
        }

        $tierKeys = array_keys($tiers);
        $dimensionKeys = array_keys((array) $this->config->get('billing.dimensions', []));

        // A configured app must define the tier its free / churned owners land on.
        $zeroTier = $this->config->get('billing.zero_tier', 'free');
        if (is_string($zeroTier) && ! in_array($zeroTier, $tierKeys, true)) {
            throw InvalidBillingConfig::zeroTierMissing($zeroTier);
        }

        foreach ((array) $this->config->get('billing.untouchable_tiers', []) as $tier) {
            if (is_string($tier) && ! in_array($tier, $tierKeys, true)) {
                throw InvalidBillingConfig::untouchableTierMissing($tier);
            }
        }

        foreach ($tiers as $key => $tier) {
            if (! is_array($tier)) {
                continue;
            }

            foreach ((array) ($tier['dimensions'] ?? []) as $dimension) {
                if (is_string($dimension) && ! in_array($dimension, $dimensionKeys, true)) {
                    throw InvalidBillingConfig::unknownDimension((string) $key, $dimension);
                }
            }

            $this->assertCurrency('tiers.'.$key.'.price_display', $tier['price_display'] ?? null);
        }

        foreach ((array) $this->config->get('billing.addons', []) as $key => $addon) {
            if (is_array($addon)) {
                $this->assertCurrency('addons.'.$key.'.price_display', $addon['price_display'] ?? null);
            }
        }
    }

    private function assertCurrency(string $where, mixed $priceDisplay): void
    {
        if (! is_array($priceDisplay) || ! isset($priceDisplay['currency'])) {
            return;
        }

        $currency = $priceDisplay['currency'];

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InvalidBillingConfig::invalidCurrency($where, is_string($currency) ? $currency : gettype($currency));
        }
    }

    private function validateDimensionWarnThresholds(): void
    {
        foreach ((array) $this->config->get('billing.dimensions', []) as $key => $dimension) {
            if (! is_array($dimension)) {
                continue;
            }
            if (! isset($dimension['warn_threshold'])) {
                continue;
            }
            $threshold = $dimension['warn_threshold'];

            if (! is_int($threshold) && ! is_float($threshold)) {
                continue;
            }

            $value = (float) $threshold;

            if ($value < 0.0 || $value > 1.0) {
                throw InvalidBillingConfig::warnThresholdOutOfRange((string) $key, $value);
            }
        }
    }

    private function validateDunningAscending(): void
    {
        $previous = null;

        foreach ((array) $this->config->get('billing.dunning', []) as $rung) {
            if (! is_array($rung)) {
                continue;
            }
            if (! isset($rung['after_days'])) {
                continue;
            }
            if (! is_int($rung['after_days'])) {
                continue;
            }
            $after = $rung['after_days'];

            if ($previous !== null && $after <= $previous) {
                throw InvalidBillingConfig::dunningNotAscending($after, $previous);
            }

            $previous = $after;
        }
    }
}
