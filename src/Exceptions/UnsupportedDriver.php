<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a billing driver is requested that has not been registered with the BillingManager.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class UnsupportedDriver extends InvalidArgumentException
{
    /**
     * A driver registers its install driver and each piece of a payment account separately, so the message names the
     * registration that is missing.
     *
     * @param  'extend'|'extendAccounts'|'extendAccountWebhooks'|'extendAccountCsp'|'extendAccountPaymentMethods'  $registration  the BillingManager method that registers what was asked for
     */
    public function __construct(string $name, public readonly string $registration = 'extend')
    {
        parent::__construct(match ($registration) {
            'extendAccounts' => "No billing driver builds itself for a payment account of '{$name}'. Register it via BillingManager::extendAccounts().",
            'extendAccountWebhooks' => "No billing driver reads the webhooks of a payment account of '{$name}'. Register them via BillingManager::extendAccountWebhooks().",
            'extendAccountCsp' => "No billing driver declares the CSP sources for a payment account of '{$name}'. Register them via BillingManager::extendAccountCsp().",
            'extendAccountPaymentMethods' => "No billing driver lists the payment methods of a payment account of '{$name}'. Register it via BillingManager::extendAccountPaymentMethods().",
            default => "Unsupported billing driver: '{$name}'. Register it via BillingManager::extend().",
        });
    }
}
