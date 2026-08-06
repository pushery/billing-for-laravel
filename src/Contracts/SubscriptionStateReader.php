<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\SubscriptionGrant;

/**
 * The read seam a consumer's content-ACL uses to ask about subscription STATE, so it never re-derives that
 * state from the provider or from the internal tier-resolver/presenter pair.
 *
 * BOUNDARY: billing owns MONEY and SUBSCRIPTION-STATE; the consumer owns CONTENT-ACL. This seam hands the
 * consumer the state — what tier, in what state, over what window, per merchant — and nothing about what a
 * tier unlocks. It is provider-free by contract: an implementation answers from local state on the hot path,
 * never a live provider read, so an access check per page never fans out to a payment provider.
 *
 * The merchant scope defaults to null — the platform, the single-seller case — so a single-merchant consumer
 * calls exactly as before. A marketplace passes a merchant to ask "is this fan subscribed to THIS creator".
 */
interface SubscriptionStateReader
{
    /**
     * Whether the customer holds an access-granting subscription on the merchant at the instant — and, when a
     * level is given, at that tier or higher (cumulative, fail-closed).
     */
    public function activeOn(Model $customer, ?MerchantScope $merchant = null, ?int $atLevel = null, ?CarbonInterface $at = null): bool;

    /** The customer's grant on the merchant, or null when there is no subscription there. */
    public function grantOn(Model $customer, ?MerchantScope $merchant = null, ?CarbonInterface $at = null): ?SubscriptionGrant;

    /**
     * Every access-granting grant the customer holds at the instant, keyed by merchant uid — the marketplace
     * read, answered in one indexed query rather than a provider call per merchant.
     *
     * @return array<string, SubscriptionGrant>
     */
    public function grantsFor(Model $customer, ?CarbonInterface $at = null): array;
}
