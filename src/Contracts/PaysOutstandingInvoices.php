<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\OutstandingPayment;

/**
 * Pays the invoice a subscription still owes, now, with the payment method the subscription charges.
 *
 * A subscription whose renewal failed stays past due until the provider tries again, on a schedule of its own that
 * can be days away, and the owner stays locked out until then. An owner who has just put a working card on file can
 * have the open invoice paid at once instead, and the subscription is active again as soon as the provider reports
 * the payment.
 *
 * The Stripe driver binds it. A driver whose provider offers no such payment leaves it unbound, so ask the container
 * with `bound()` before offering it. A subscription paid from a balance owes the provider nothing.
 */
interface PaysOutstandingInvoices
{
    /**
     * Pay the open invoice of the subscription the owner holds with the merchant and of the type, or say why it was
     * not paid.
     *
     * A declined method and a payment the cardholder has to confirm (3-D Secure) are outcomes, not exceptions. An
     * error that says nothing about the payment, such as a rejected key, a rate limit or an outage, is thrown, so the
     * call can be made again.
     *
     * @throws EligibilityDenied when the owner may not move money
     */
    public function payOutstanding(Model $billable, ?MerchantScope $merchant = null, ?string $type = null): OutstandingPayment;
}
