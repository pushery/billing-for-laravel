<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\BillingInterval;

/**
 * One merchant-defined tier, as the host hands it to the package.
 *
 * A marketplace's creators each define their own tiers as database rows, so the package cannot read them
 * from config — but it still must not read a price the client submitted. This row is the boundary: the host
 * fills it from its own `creator_tiers` table (a schema the host owns, never the package), and the package
 * reads the price ONLY from here. The tier KEY is still the client's input; the amount and the provider
 * price are the merchant's, carried on the row and never anywhere the client can reach.
 *
 * It is the database analog of one `billing.tiers.<key>` config block, and it produces the same value
 * objects — a TierIdentity, a display Money, a Plan — so the database-backed catalogs answer exactly what the
 * config-backed ones do, only per merchant.
 */
final readonly class MerchantTierRow
{
    /**
     * @param  list<string>  $legacyPrices  provider price ids that still RESOLVE to this tier (a rotated or
     *                                      grandfathered price) but are never sold anew
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $amountMinor,
        public string $currency,
        public BillingInterval $interval = BillingInterval::Month,
        public ?string $providerPrice = null,
        public bool $byok = false,
        public bool $untouchable = false,
        public array $legacyPrices = [],
    ) {}

    /**
     * The tier's identity — never implies access on its own, exactly like the config catalog's. The rank is
     * the tier's position in the merchant's own ordered list, which the catalog knows and the row does not,
     * so it is passed in.
     */
    public function identity(int $level = 0): TierIdentity
    {
        return new TierIdentity($this->key, $this->label, $this->byok, $this->untouchable, $level);
    }

    /** The static price to display for the tier. */
    public function priceDisplay(): Money
    {
        return Money::of($this->amountMinor, $this->currency);
    }

    /** The purchasable plan — amount, interval and the merchant's own provider price. */
    public function plan(): Plan
    {
        return new Plan(
            key: $this->key,
            amount: $this->priceDisplay(),
            interval: $this->interval,
            providerPriceId: $this->providerPrice,
        );
    }
}
