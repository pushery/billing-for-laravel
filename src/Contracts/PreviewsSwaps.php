<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a swap would cost, asked before it is made, with the arguments `SubscriptionActions::swap()` takes.
 *
 * The preview prices the change the swap would make: the same subscription, the same item and the same price,
 * at the interval the swap would choose where none is named. It is a capability of the provider. The Stripe driver
 * binds it; a driver without a preview binds nothing, so a host asks the container whether it is bound before it
 * offers a figure.
 */
interface PreviewsSwaps
{
    /**
     * What the invoice that follows the swap asks the customer to pay, net of any credit balance the customer
     * holds, or null where there is nothing to preview or the provider gave no answer.
     *
     * Null covers every case in which `swap()` with the same arguments would be refused (no subscription, a tier
     * without a price at the interval, an item that cannot be told), so a screen shows no figure rather than an
     * error, and the swap itself still says why.
     */
    public function previewSwap(Model $billable, string $tierKey, bool $prorate = true, ?MerchantScope $merchant = null, ?string $type = null, ?BillingInterval $interval = null): ?Money;
}
