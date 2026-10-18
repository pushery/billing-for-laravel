<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Letting a subscription rest without canceling it, and taking it up again.
 *
 * While it rests nothing is collected and the subscription grants no access. Not every driver can do this, so it is a
 * contract of its own beside {@see SubscriptionActions}: ask the container whether the bound actions implement it
 * before offering a pause.
 *
 * The package's own engine adds the time a pause took to the period the subscription is in when it goes on, so the
 * days a customer had paid for, or would have been billed for, are still there afterwards. Stripe pauses collection
 * with `pause_collection` and keeps its own periods.
 */
interface PausesSubscriptions
{
    /**
     * Stop collecting the subscription until it is taken up again.
     *
     * Only an active subscription that is not ending can rest: one in arrears would put its debt on hold, and one
     * that is ending has nothing left to rest. The merchant scope and the contract type work as on every
     * {@see SubscriptionActions} method.
     *
     * @throws InvalidArgumentException when the subscription cannot be paused
     */
    public function pause(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void;

    /** Collect the paused subscription again, from now. A subscription that is not paused is left alone. */
    public function unpause(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): void;
}
