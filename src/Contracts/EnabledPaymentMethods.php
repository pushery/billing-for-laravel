<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\ValueObjects\EnabledPaymentMethod;
use Pushery\Billing\ValueObjects\Money;

/**
 * The payment methods a payment account has turned on at its provider, for a settings screen that shows them and a
 * checkout that offers them.
 *
 * Built per account by {@see BillingManager::accountPaymentMethods()}, so the answer is that
 * account's, read with its own credential. The methods one customer has stored are {@see PaymentMethods}.
 */
interface EnabledPaymentMethods
{
    /**
     * The methods the account accepts a one-off payment with.
     *
     * Each filter narrows what the provider is able to narrow: with an amount, the methods that take it in its
     * currency; with a billing country, the methods offered there; the locale sets the language of the names. Each
     * driver documents which of them its provider applies.
     *
     * @param  ?string  $country  the customer's billing country, ISO 3166-1 alpha-2
     * @param  ?string  $locale  the language of the names, such as `de_DE`
     * @return list<EnabledPaymentMethod>
     */
    public function all(?Money $amount = null, ?string $country = null, ?string $locale = null): array;
}
