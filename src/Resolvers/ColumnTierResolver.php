<?php

declare(strict_types=1);

namespace Pushery\Billing\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * Resolves a billable's tier from a raw column (config('billing.tier_column'), default "plan"). It
 * reads the RAW attribute via getAttributes() rather than the magic accessor on purpose: an app
 * accessor could resolve a relationship (or the tier itself) and recurse. Any value that is not a
 * known tier fails safe to the configured zero-tier — a resolver never implies access.
 */
final readonly class ColumnTierResolver implements TierResolver
{
    public function __construct(
        private Repository $config,
        private TierCatalog $catalog,
    ) {}

    /**
     * The merchant scope is accepted to satisfy the contract but deliberately ignored: a single
     * denormalized column cannot express "Pro on creator A, free on creator B". This resolver stays the
     * single-seller reading; a marketplace app rebinds TierResolver to SubscriptionTierResolver, which keys
     * a real per-merchant row.
     */
    public function resolve(Model $billable, ?MerchantScope $merchant = null): TierIdentity
    {
        $column = $this->string('billing.tier_column', 'plan');
        $raw = $billable->getAttributes()[$column] ?? null;
        $tier = is_string($raw) ? $this->catalog->find($raw) : null;

        return $tier ?? $this->zeroTier();
    }

    private function zeroTier(): TierIdentity
    {
        $zero = $this->string('billing.zero_tier', 'free');

        return $this->catalog->find($zero) ?? new TierIdentity($zero, $zero);
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
