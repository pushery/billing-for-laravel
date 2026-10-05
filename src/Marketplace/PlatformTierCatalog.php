<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * The platform's own tiers on a marketplace: the configured catalog first, then the platform's rows for every key
 * the configuration does not declare.
 *
 * The configured catalog comes first because a platform sale is priced from it: the checkout reads the bound
 * {@see TierCatalog} and plan catalog for a sale with no merchant. A webhook, a swap or the revenue report that
 * read only the platform's rows found no tier for a price the checkout had just charged, so the customer paid and
 * never received the tier, with no error anywhere. The platform's rows keep answering for the keys the
 * configuration leaves out, ranked after the configured ones, so an installation that keeps platform tiers as
 * rows loses none of them.
 *
 * One order decides the levels: the configured keys in their order, then the rows' keys in theirs. Each catalog
 * counts from zero on its own, and two first tiers at level 0 would make a move between them neither an upgrade
 * nor a downgrade.
 */
final readonly class PlatformTierCatalog implements TierCatalog
{
    public function __construct(
        private TierCatalog $configured,
        private TierCatalog $rows,
    ) {}

    public function all(): array
    {
        $out = [];
        $level = 0;

        foreach ([$this->configured->all(), $this->rows->all()] as $tiers) {
            foreach ($tiers as $key => $identity) {
                if (array_key_exists($key, $out)) {
                    continue;
                }

                $out[$key] = new TierIdentity($identity->key, $identity->label, $identity->byok, $identity->untouchable, $level);
                $level++;
            }
        }

        return $out;
    }

    public function find(string $key): ?TierIdentity
    {
        return $this->all()[$key] ?? null;
    }

    public function label(string $key): string
    {
        return $this->declaring($key)->label($key);
    }

    public function isByok(string $key): bool
    {
        return $this->declaring($key)->isByok($key);
    }

    public function isUntouchable(string $key): bool
    {
        return $this->declaring($key)->isUntouchable($key);
    }

    public function level(string $key): int
    {
        $index = array_search($key, array_keys($this->all()), true);

        return $index === false ? -1 : $index;
    }

    public function priceDisplay(string $key): ?Money
    {
        return $this->declaring($key)->priceDisplay($key);
    }

    /** The catalog that answers for this key: the configured one when it declares it, the platform's rows otherwise. */
    private function declaring(string $key): TierCatalog
    {
        return $this->configured->find($key) instanceof TierIdentity ? $this->configured : $this->rows;
    }
}
