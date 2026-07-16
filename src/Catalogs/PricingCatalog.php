<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Entitlements\ConfigEntitlements;
use Pushery\Billing\Entitlements\ConfigEntitlementsFactory;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PricingCard;

/**
 * The pricing-screen read model: every configured tier as an entitlement view, in upgrade order, so
 * a pricing table can render labels, prices and dimensions without touching a provider. purchasable()
 * narrows to the tiers that actually have a display price (dropping the free tier), which is what a
 * plan-picker offers.
 *
 * {@see cards()} is the ONE source the in-app upgrade grid and the public /pricing page both render from,
 * so the two can never promise different things. The feature bullets live in config (`tiers.<key>.features`,
 * a list of translation keys) — never hard-coded in a view — which is what makes drift impossible: change
 * the config and both surfaces move together.
 */
final readonly class PricingCatalog
{
    public function __construct(
        private TierCatalog $catalog,
        private ConfigEntitlementsFactory $entitlements,
        private Repository $config,
        private Translator $translator,
    ) {}

    /** @return list<ConfigEntitlements> */
    public function tiers(): array
    {
        return array_map(
            $this->entitlements->for(...),
            array_keys($this->catalog->all()),
        );
    }

    /** @return list<ConfigEntitlements> */
    public function purchasable(): array
    {
        return array_values(array_filter(
            $this->tiers(),
            fn (ConfigEntitlements $tier): bool => $tier->priceDisplay() instanceof Money,
        ));
    }

    /**
     * One {@see PricingCard} per tier, in upgrade order — the shared source the in-app grid AND /pricing
     * render. Label, price and BYOK come from the tier catalog; bullets, highlight and badge from config.
     *
     * @return list<PricingCard>
     */
    public function cards(): array
    {
        return array_map(
            fn (string $key): PricingCard => new PricingCard(
                tierKey: $key,
                label: $this->catalog->label($key),
                priceDisplay: $this->catalog->priceDisplay($key),
                byok: $this->catalog->isByok($key),
                bullets: $this->bulletsFor($key),
                highlighted: $this->config->get("billing.tiers.{$key}.highlight") === true,
                badge: $this->badgeFor($key),
            ),
            array_keys($this->catalog->all()),
        );
    }

    /**
     * The cards a CURRENT-tier owner can upgrade to — the purchasable tiers ranked above their tier, in
     * upgrade order. This is what the in-app upgrade grid renders, as opposed to {@see cards()} (the full
     * set a /pricing page shows); both render from the SAME {@see PricingCard} model, so they cannot drift.
     *
     * @return list<PricingCard>
     */
    public function upgradeCards(string $currentTierKey): array
    {
        $current = $this->entitlements->for($currentTierKey);

        return array_values(array_filter(
            $this->cards(),
            fn (PricingCard $card): bool => $card->priceDisplay instanceof Money
                && $this->entitlements->for($card->tierKey)->isUpgradeOver($current),
        ));
    }

    /**
     * A tier's feature bullets, resolved from its configured translation keys to the current locale, in the
     * order they are listed. An unconfigured / malformed `features` entry yields no bullets rather than a
     * raw key on the page.
     *
     * @return list<string>
     */
    public function bulletsFor(string $tierKey): array
    {
        $keys = $this->config->get("billing.tiers.{$tierKey}.features");

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_map(
            $this->resolve(...),
            array_filter($keys, is_string(...)),
        ));
    }

    /** The tier's badge label (a translation key), resolved to the current locale, or null when unset. */
    private function badgeFor(string $tierKey): ?string
    {
        $badge = $this->config->get("billing.tiers.{$tierKey}.badge");

        return is_string($badge) && $badge !== '' ? $this->resolve($badge) : null;
    }

    /** Resolve a translation key to a string; a key that resolves to a group (misconfigured) falls back to itself. */
    private function resolve(string $key): string
    {
        $value = $this->translator->get($key);

        return is_string($value) ? $value : $key;
    }
}
