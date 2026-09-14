<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/**
 * The provider reported which country it taxed a sale in.
 *
 * Emitted for a settled one-time checkout and for every invoice of a subscription, because those are the moments
 * the provider states the buyer's location for tax: a hosted checkout from the address the buyer entered in the
 * session, a subscription invoice from the customer's shipping address and otherwise their billing address. It
 * carries no verdict. Whether the country is one this platform may sell into is `billing.tax_markets`' question,
 * and the effect behind this event asks it.
 *
 * `country` is null when the payload named none. `chargeReference` is what a refund goes against, the payment
 * intent of a checkout or the invoice of a subscription cycle, and `paid` says whether there is money to return
 * yet: an invoice is reported once when it is finalized and again when it is paid.
 */
final readonly class SaleCountryReported implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $saleReference,
        public ?string $country,
        public Money $amount,
        public bool $paid,
        public ?string $chargeReference = null,
        public ?string $subscriptionReference = null,
    ) {}
}
