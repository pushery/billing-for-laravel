<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Dunning\ConfigDunningLadder;
use Pushery\Billing\Enums\BuyerAudience;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Tax\DistanceSaleThresholdMonitor;

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
    public function __construct(
        private Repository $config,
        private DistanceSaleThresholdMonitor $thresholds,
    ) {}

    public function validate(): void
    {
        $this->validateOwner();
        $this->validateTiers();
        $this->validateAddons();
        $this->validateDimensionWarnThresholds();
        $this->validateDunningAscending();
        $this->validateThresholdBinding();
    }

    /**
     * A declaration that gave up the threshold binds for a number of years, and withdrawing it early is a
     * configuration change nobody would otherwise notice.
     *
     * It has to fail at boot rather than at the next sale. Discovered at the sale, the fallback is a quiet
     * one — the seller's own rate on a supply that owes the destination's — and every document issued in
     * between is wrong in a way that reads as normal. The binding length itself comes from the operator,
     * because it is their tax authority's term, not the package's.
     */
    private function validateThresholdBinding(): void
    {
        $years = $this->config->get('billing.tax_oss.binding_years');

        $this->thresholds->assertBindingHonored(
            (int) Carbon::now()->year,
            is_int($years) && $years > 0 ? $years : 2,
        );
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
            $this->assertAudience('billing.tiers.'.$key.'.buyers', $tier['buyers'] ?? null);
        }
    }

    /**
     * The add-ons, checked whether or not any tier is configured: an install that sells only add-ons has no
     * tiers, and its add-ons are what a customer pays for.
     */
    private function validateAddons(): void
    {
        foreach ((array) $this->config->get('billing.addons', []) as $key => $addon) {
            if (is_array($addon)) {
                $this->assertCurrency('addons.'.$key.'.price_display', $addon['price_display'] ?? null);
                $this->assertGrant((string) $key, $addon['grants'] ?? null);
                $this->assertAudience('billing.addons.'.$key.'.buyers', $addon['buyers'] ?? null);
            }
        }
    }

    /**
     * An add-on's unit grant reads as a non-empty meter and a positive whole number of units, or is absent.
     *
     * Checked here because every reader of it runs after the customer has paid. A grant the catalog cannot read
     * fails the webhook that should hand the units over, and a scalar in its place, taken for no grant, would
     * credit money instead. A value read through `env()` arrives as a string, the ordinary way to get there.
     */
    private function assertGrant(string $key, mixed $grant): void
    {
        if ($grant === null) {
            return;
        }

        $meter = is_array($grant) ? ($grant['meter'] ?? null) : null;
        $units = is_array($grant) ? ($grant['units'] ?? null) : null;

        if (! is_string($meter) || $meter === '' || ! is_int($units) || $units <= 0) {
            throw InvalidBillingConfig::forKey(
                'billing.addons.'.$key.'.grants',
                "must be ['meter' => a meter key, 'units' => a positive whole number], or absent",
            );
        }
    }

    /**
     * Who may buy an offer reads as one of the audiences, or is absent.
     *
     * Refused at boot because the catalog refuses it when the hub asks, and a typo read as "anyone" would sell an
     * offer meant for businesses to consumers.
     */
    private function assertAudience(string $where, mixed $buyers): void
    {
        if ($buyers === null || (is_string($buyers) && BuyerAudience::tryFrom($buyers) instanceof BuyerAudience)) {
            return;
        }

        throw InvalidBillingConfig::forKey($where, "must be 'anyone' or 'business', or absent");
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
            // Read the way the ladder reads it, so a rung written as a string of digits is checked rather than
            // passed over, and a rung the ladder drops is not checked either.
            $after = ConfigDunningLadder::wholeNumber($rung['after_days'] ?? null);
            if ($after === null) {
                continue;
            }

            if ($previous !== null && $after <= $previous) {
                throw InvalidBillingConfig::dunningNotAscending($after, $previous);
            }

            $previous = $after;
        }
    }
}
