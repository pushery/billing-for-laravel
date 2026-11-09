<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\PaymentAccount;

/**
 * The application's payment accounts of one provider, for the work the package does on them without a webhook.
 *
 * `PaymentAccounts` finds one account by its provider and key, which is all a webhook needs. Mollie calls no webhook for
 * a refund that is canceled before it is paid out, so `billing:accounts:reconcile` reads the refunds of each account back
 * on a schedule, and to do that it has to know the accounts. An application whose `PaymentAccounts` also implements
 * this interface gets that run; one that does not behaves as before.
 *
 * This lives beside {@see PaymentAccounts} rather than on it, because that contract is public and a method added to it
 * would be a fatal error in every implementation that exists.
 */
interface ListsPaymentAccounts
{
    /**
     * Every account the application knows under the provider, such as `mollie`.
     *
     * @return iterable<PaymentAccount>
     */
    public function all(string $provider): iterable;
}
