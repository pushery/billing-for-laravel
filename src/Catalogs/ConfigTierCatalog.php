<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The config-driven tier catalog: reads config('billing.tiers') (an ordered map keyed by tier key)
 * and answers identity/label/price-display questions. The configured order is the upgrade ranking.
 */
final readonly class ConfigTierCatalog implements TierCatalog
{
    public function __construct(private Repository $config) {}

    public function all(): array
    {
        $tiers = $this->config->get('billing.tiers');

        if (! is_array($tiers)) {
            return [];
        }

        $out = [];

        foreach (array_keys($tiers) as $key) {
            $key = (string) $key;
            $out[$key] = $this->identity($key);
        }

        return $out;
    }

    public function find(string $key): ?TierIdentity
    {
        return is_array($this->config->get("billing.tiers.{$key}")) ? $this->identity($key) : null;
    }

    public function label(string $key): string
    {
        $label = $this->config->get("billing.tiers.{$key}.label");

        return is_string($label) ? $label : $key;
    }

    public function isByok(string $key): bool
    {
        return $this->config->get("billing.tiers.{$key}.byok") === true;
    }

    public function isUntouchable(string $key): bool
    {
        return $this->config->get("billing.tiers.{$key}.untouchable") === true;
    }

    public function priceDisplay(string $key): ?Money
    {
        $amount = $this->config->get("billing.tiers.{$key}.price_display.amount");
        $currency = $this->config->get("billing.tiers.{$key}.price_display.currency");

        return is_int($amount) && is_string($currency) ? Money::of($amount, $currency) : null;
    }

    private function identity(string $key): TierIdentity
    {
        return new TierIdentity(
            key: $key,
            label: $this->label($key),
            byok: $this->isByok($key),
            untouchable: $this->isUntouchable($key),
        );
    }
}
