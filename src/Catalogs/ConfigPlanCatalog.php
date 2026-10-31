<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Support\KeyedConfig;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The purchasable-plan catalog and the anti-price-injection guarantee: the client submits a tier
 * key, and the price is resolved here from config — never a price id from the client. A plan is a
 * first-class local concept (amount + interval) that may carry a provider-price mapping.
 *
 * A tier's own `provider_price`, `price_display` and `legacy_prices` sell it at its `interval`. Each entry
 * under its `intervals`, keyed by another interval, sells the same tier at that one with keys of the same names.
 */
final readonly class ConfigPlanCatalog implements PlanCatalog
{
    public function __construct(
        private Repository $config,
        private TierCatalog $tiers,
        private ProviderPriceResolver $prices,
    ) {}

    public function planFor(string $tierKey, ?BillingInterval $interval = null): ?Plan
    {
        $interval ??= $this->defaultInterval($tierKey);
        $amount = $this->setting($tierKey, $interval, 'price_display.amount');
        $currency = $this->setting($tierKey, $interval, 'price_display.currency');

        if (! is_int($amount) || ! is_string($currency)) {
            return null;
        }

        return new Plan(
            key: $tierKey,
            amount: Money::of($amount, $currency),
            interval: $interval,
            providerPriceId: $this->providerPriceFor($tierKey, $interval),
        );
    }

    public function providerPriceFor(string $tierKey, ?BillingInterval $interval = null): ?string
    {
        // Delegates to the resolver so a per-provider price map resolves to the active driver's id, not
        // just a scalar. The tier key is the client's input; the price never is (anti-price-injection).
        if (! $interval instanceof BillingInterval || $interval === $this->defaultInterval($tierKey)) {
            return $this->prices->forTier($tierKey);
        }

        return in_array($interval, $this->otherIntervals($tierKey), true) ? $this->prices->forTierAt($tierKey, $interval) : null;
    }

    public function legacyPricesFor(string $tierKey): array
    {
        $lists = [KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'legacy_prices')];

        foreach ($this->otherIntervals($tierKey) as $interval) {
            $lists[] = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, "intervals.{$interval->value}.legacy_prices");
        }

        $prices = [];

        foreach ($lists as $list) {
            if (is_array($list)) {
                array_push($prices, ...array_values(array_filter($list, is_string(...))));
            }
        }

        return $prices;
    }

    public function intervalsFor(string $tierKey): array
    {
        return [$this->defaultInterval($tierKey), ...$this->otherIntervals($tierKey)];
    }

    public function options(TierIdentity $current): array
    {
        $out = [];

        foreach (array_keys($this->tiers->all()) as $key) {
            if ($key === $current->key) {
                continue;
            }

            // An untouchable tier is a manually-granted plan (comp / lifetime) the provider webhook must not
            // overwrite — it is not self-service purchasable, so it must never be offered as a swap target.
            if ($this->tiers->isUntouchable($key)) {
                continue;
            }

            $plan = $this->planFor($key);

            if ($plan instanceof Plan) {
                $out[] = $plan;
            }
        }

        return $out;
    }

    /** The interval the tier's own keys sell it at: its `interval`, and a month without a valid one. */
    private function defaultInterval(string $tierKey): BillingInterval
    {
        $configured = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'interval');

        return (is_string($configured) ? BillingInterval::tryFrom($configured) : null) ?? BillingInterval::Month;
    }

    /**
     * The further intervals `intervals` names, in the order it names them. A key that is no interval, the default
     * interval, which the tier's own keys describe, and an entry that is no list are left out.
     *
     * @return list<BillingInterval>
     */
    private function otherIntervals(string $tierKey): array
    {
        $configured = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'intervals');

        if (! is_array($configured)) {
            return [];
        }

        $default = $this->defaultInterval($tierKey);
        $out = [];

        foreach ($configured as $key => $entry) {
            $interval = is_string($key) ? BillingInterval::tryFrom($key) : null;

            if ($interval instanceof BillingInterval && $interval !== $default && is_array($entry)) {
                $out[] = $interval;
            }
        }

        return $out;
    }

    /** A setting of the tier at an interval: the tier's own key at its default interval, its `intervals` entry at another. */
    private function setting(string $tierKey, BillingInterval $interval, string $setting): mixed
    {
        if ($interval === $this->defaultInterval($tierKey)) {
            return KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, $setting);
        }

        return in_array($interval, $this->otherIntervals($tierKey), true)
            ? KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, "intervals.{$interval->value}.{$setting}")
            : null;
    }
}
