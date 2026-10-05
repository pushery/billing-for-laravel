<?php

declare(strict_types=1);

namespace Pushery\Billing\Testing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pushery\Billing\Contracts\SubscriptionStateReader;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\SubscriptionGrant;

/**
 * An in-memory subscription-state reader, so a consumer can test their content-ACL without a billing
 * database.
 *
 * ## Why this ships rather than being left to each consumer
 *
 * The ACL question — may this person see this creator's post right now — is asked on nearly every page a
 * marketplace renders, so it is the read a consumer's own suite exercises most. Standing up
 * `billing_subscriptions` to answer it drags a package's schema into tests about somebody else's content.
 *
 * The risk in a double is that it drifts: a hand-rolled stub that grants access whenever a tier is present
 * would let a consumer ship an ACL that shows paid content to a lapsed subscriber, and their suite would be
 * green. So this delegates every decision to the SAME value object the real reader returns —
 * `SubscriptionGrant::grantsAccess()`, `atLeast()` and `coversInstant()` — and only the storage is fake.
 * Define grants with `grant()`; what they then mean is not this class's opinion.
 */
final class ArraySubscriptionStateReader implements SubscriptionStateReader
{
    /** @var array<string, array<string, SubscriptionGrant>> keyed by customer key, then merchant uid */
    private array $grants = [];

    /** Record a grant for a customer, replacing any it already had on that merchant. */
    public function grant(Model $customer, SubscriptionGrant $grant): self
    {
        $this->grants[$this->keyFor($customer)][($grant->merchant ?? MerchantScope::platform())->uid()] = $grant;

        return $this;
    }

    public function activeOn(Model $customer, ?MerchantScope $merchant = null, ?int $atLevel = null, ?CarbonInterface $at = null): bool
    {
        $grant = $this->grantOn($customer, $merchant, $at);

        if (! $grant instanceof SubscriptionGrant) {
            return false;
        }

        // A missing level means "any access-granting grant will do". A level asks the cumulative question,
        // and `atLeast()` answers it the same way for the fake as for the real reader, which then asks the
        // window at the moment as well.
        $granted = $atLevel === null ? $grant->grantsAccess() : $grant->atLeast($atLevel);

        return $granted && $grant->coversInstant($at ?? Carbon::now());
    }

    /**
     * The grant as recorded, its window and state included, and null only where none was recorded: the answer
     * the real reader gives, which returns a lapsed subscription's grant rather than nothing. An ACL that reads
     * the grant has to read its state and window the way production will, or a suite that says "lapsed, no
     * access" stays green over code that grants it.
     */
    public function grantOn(Model $customer, ?MerchantScope $merchant = null, ?CarbonInterface $at = null): ?SubscriptionGrant
    {
        return $this->grants[$this->keyFor($customer)][($merchant ?? MerchantScope::platform())->uid()] ?? null;
    }

    /** @return array<string, SubscriptionGrant> */
    public function grantsFor(Model $customer, ?CarbonInterface $at = null): array
    {
        $moment = $at ?? Carbon::now();

        return array_filter(
            $this->grants[$this->keyFor($customer)] ?? [],
            static fn (SubscriptionGrant $grant): bool => $grant->grantsAccess() && $grant->coversInstant($moment),
        );
    }

    /**
     * Two customers of different classes can share a primary key, so the class is part of the key.
     *
     * Without it a fan and a creator with the same id would read each other's grants — in a marketplace,
     * where both are ordinary models, that is not a remote possibility but the common case.
     */
    private function keyFor(Model $customer): string
    {
        $key = $customer->getKey();

        if (! is_int($key) && ! is_string($key)) {
            // An unsaved model has no identity, so every one of them would key alike — a test could grant to
            // one and read back from another and never notice. Refused for the same reason MerchantScope
            // refuses an unsaved merchant: a double that quietly answers is worse than one that stops.
            throw new InvalidArgumentException(
                'A subscription grant needs a saved customer: an unsaved model has no key, so every unsaved '.
                'model would share one and read back each other’s grants.'
            );
        }

        return $customer->getMorphClass().'#'.$key;
    }
}
