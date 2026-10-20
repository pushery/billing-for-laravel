<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Reminds an owner that a subscription they canceled ends soon, while resuming it still keeps the plan without a gap.
 *
 * A contract of its own rather than a method on {@see SubscriptionNotifier}, so an application that implements that
 * one keeps working. The package's notifier answers both.
 */
interface SubscriptionEndingNotifier
{
    /**
     * @param  MerchantScope|null  $merchant  the merchant the subscription is held with, or null for the platform's own
     * @param  string|null  $merchantName  that merchant's name, where one is known
     */
    public function subscriptionEnding(Model $owner, DateTimeInterface $endsAt, ?MerchantScope $merchant = null, ?string $merchantName = null): void;
}
