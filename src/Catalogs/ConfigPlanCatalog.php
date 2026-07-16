<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The purchasable-plan catalog and the anti-price-injection guarantee: the client submits a tier
 * KEY, and the price is resolved here from config — never a price id from the client. A plan is a
 * first-class local concept (amount + interval) that MAY carry a provider-price mapping.
 */
final readonly class ConfigPlanCatalog implements PlanCatalog
{
    public function __construct(
        private Repository $config,
        private TierCatalog $tiers,
        private ProviderPriceResolver $prices,
    ) {}

    public function planFor(string $tierKey): ?Plan
    {
        $amount = $this->config->get("billing.tiers.{$tierKey}.price_display.amount");
        $currency = $this->config->get("billing.tiers.{$tierKey}.price_display.currency");

        if (! is_int($amount) || ! is_string($currency)) {
            return null;
        }

        $configured = $this->config->get("billing.tiers.{$tierKey}.interval");
        $interval = (is_string($configured) ? BillingInterval::tryFrom($configured) : null) ?? BillingInterval::Month;

        return new Plan(
            key: $tierKey,
            amount: Money::of($amount, $currency),
            interval: $interval,
            providerPriceId: $this->providerPriceFor($tierKey),
        );
    }

    public function providerPriceFor(string $tierKey): ?string
    {
        // Delegates to the resolver so a per-provider price map resolves to the active driver's id, not
        // just a scalar. The tier KEY is the client's input; the price never is (anti-price-injection).
        return $this->prices->forTier($tierKey);
    }

    public function legacyPricesFor(string $tierKey): array
    {
        $prices = $this->config->get("billing.tiers.{$tierKey}.legacy_prices");

        return is_array($prices) ? array_values(array_filter($prices, is_string(...))) : [];
    }

    public function options(TierIdentity $current): array
    {
        $out = [];

        foreach (array_keys($this->tiers->all()) as $key) {
            if ($key === $current->key) {
                continue;
            }

            $plan = $this->planFor($key);

            if ($plan instanceof Plan) {
                $out[] = $plan;
            }
        }

        return $out;
    }
}
