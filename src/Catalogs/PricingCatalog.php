<?php

declare(strict_types=1);

namespace Pushery\Billing\Catalogs;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Entitlements\ConfigEntitlements;
use Pushery\Billing\Entitlements\ConfigEntitlementsFactory;
use Pushery\Billing\Support\CatalogLabel;
use Pushery\Billing\Support\KeyedConfig;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PricingCard;

/**
 * The pricing-screen read model: every configured tier as an entitlement view, in upgrade order, so
 * a pricing table can render labels, prices and dimensions without touching a provider. purchasable()
 * narrows to the tiers that actually have a display price (dropping the free tier), which is what a
 * plan-picker offers.
 *
 * {@see cards()} is the one source for the pricing surfaces an application builds, a public /pricing page and
 * an upgrade grid of its own ({@see upgradeCards()}), so two such surfaces can never promise different things.
 * The account hub's plan list reads label and price from the same tier catalog and shows no bullets, highlight
 * or badge. The feature bullets live in config (`tiers.<key>.features`, a list of translation keys) — never
 * hard-coded in a view — which is what makes drift impossible: change the config and every surface that renders
 * them moves with it.
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
     * One {@see PricingCard} per tier, in upgrade order: what a /pricing page renders, and the set
     * {@see upgradeCards()} narrows. Label, price and BYOK come from the tier catalog; bullets, highlight and
     * badge from config.
     *
     * @return list<PricingCard>
     */
    public function cards(): array
    {
        return array_map(
            fn (string $key): PricingCard => new PricingCard(
                tierKey: $key,
                label: CatalogLabel::translate($this->catalog->label($key)),
                priceDisplay: $this->catalog->priceDisplay($key),
                byok: $this->catalog->isByok($key),
                bullets: $this->bulletsFor($key),
                highlighted: KeyedConfig::setting($this->config, 'billing.tiers', $key, 'highlight') === true,
                badge: $this->badgeFor($key),
            ),
            array_keys($this->catalog->all()),
        );
    }

    /**
     * The cards a CURRENT-tier owner can upgrade to — the purchasable tiers ranked above their tier, in
     * upgrade order. This is what an upgrade grid the application builds renders, as opposed to {@see cards()}
     * (the full set a /pricing page shows); both render from the SAME {@see PricingCard} model, so they cannot
     * drift.
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
        $keys = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'features');

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
        $badge = KeyedConfig::setting($this->config, 'billing.tiers', $tierKey, 'badge');

        return is_string($badge) && $badge !== '' ? $this->resolve($badge) : null;
    }

    /** Resolve a translation key to a string; a key that resolves to a group (misconfigured) falls back to itself. */
    private function resolve(string $key): string
    {
        $value = $this->translator->get($key);

        return is_string($value) ? $value : $key;
    }
}
