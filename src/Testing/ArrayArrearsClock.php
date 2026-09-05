<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\ArrearsClock;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * An in-memory arrears clock, so the suspension ladder can be exercised without this package's schema.
 *
 * ## Why this ships rather than being left to each consumer
 *
 * The seam exists for a consumer whose arrears live in their own table; a consumer in that position has, by
 * definition, no `billing_subscriptions` to seed. Leaving them to hand-roll a double would put the one
 * decision the ladder makes — which rung, which surface — behind a stub whose behavior nobody checked, and
 * a stub is exactly where a lockout quietly stops happening.
 *
 * So the storage is fake and NOTHING else is: the rung still comes from the configured schedule and the
 * withdrawal still comes from the policy, because this class answers one question and holds no opinion
 * about what the answer means. It is the same split `ArraySubscriptionStateReader` makes next door.
 */
final class ArrayArrearsClock implements ArrearsClock
{
    /** @var array<string, array<string, DateTimeInterface>> keyed by owner key, then merchant uid */
    private array $clocks = [];

    /** Put this owner behind with this merchant since the given instant, replacing any clock they had. */
    public function behindSince(Model $owner, DateTimeInterface $since, ?MerchantScope $merchant = null): self
    {
        $this->clocks[$this->keyFor($owner)][($merchant ?? MerchantScope::platform())->uid()] = $since;

        return $this;
    }

    public function delinquentSince(Model $owner, ?MerchantScope $merchant = null): ?DateTimeInterface
    {
        return $this->clocks[$this->keyFor($owner)][($merchant ?? MerchantScope::platform())->uid()] ?? null;
    }

    /**
     * The owner's identity as this store keys it.
     *
     * The morph class and the key together, never the key alone: two different owner types can hold the
     * same primary key, and a store keyed on the number would hand one of them the other's arrears. In a
     * marketplace that is the common case rather than a remote one — a fan and a creator are both ordinary
     * models numbered from one.
     */
    private function keyFor(Model $owner): string
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            // An unsaved model has no identity, so every one of them would key alike: a test could put one
            // owner behind and read the arrears back off another without noticing. Refused rather than
            // answered, for the same reason the sibling store refuses one.
            throw new InvalidArgumentException(
                'An arrears clock needs a saved owner: an unsaved model has no key, so every unsaved model '.
                'would share one and read back each other’s arrears.'
            );
        }

        return $owner->getMorphClass().'#'.$key;
    }
}
