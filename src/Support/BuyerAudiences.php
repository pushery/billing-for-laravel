<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Contracts\SuppliesBuyerAudiences;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BuyerAudience;

/**
 * Whether an owner may buy a tier or an add-on, by the audience its catalog names.
 *
 * The account hub asks it before it offers an offer and again before it sells one. A screen of the application's
 * own that sells the same offers asks the same question here, so the answer is drawn in one place.
 */
final readonly class BuyerAudiences
{
    public function __construct(
        private AddonCatalog $addons,
        private TierCatalog $tiers,
        private IdentifiesBusinessBuyers $businesses,
    ) {}

    /** Who may buy this add-on. */
    public function forAddon(string $key): BuyerAudience
    {
        return $this->addons instanceof SuppliesBuyerAudiences ? $this->addons->audienceFor($key) : BuyerAudience::Anyone;
    }

    /** Who may buy this tier. */
    public function forTier(string $key): BuyerAudience
    {
        return $this->tiers instanceof SuppliesBuyerAudiences ? $this->tiers->audienceFor($key) : BuyerAudience::Anyone;
    }

    /** Whether this owner may buy the add-on. */
    public function mayBuyAddon(Model $owner, string $key): bool
    {
        return $this->admits($owner, $this->forAddon($key));
    }

    /** Whether this owner may buy the tier. */
    public function mayBuyTier(Model $owner, string $key): bool
    {
        return $this->admits($owner, $this->forTier($key));
    }

    private function admits(Model $owner, BuyerAudience $audience): bool
    {
        return $audience === BuyerAudience::Anyone || $this->businesses->isBusiness($owner);
    }
}
