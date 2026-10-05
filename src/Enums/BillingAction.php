<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use Pushery\Billing\Contracts\BillingActionUrls;

/**
 * Where a billing notice sends its reader to act on what it says, one screen of the account hub each.
 *
 * The value is the last part of the name of the hub route it stands for, which is where the package's own answer
 * points. An application that manages some owners on screens of its own answers with its screen for the same purpose
 * through {@see BillingActionUrls}.
 */
enum BillingAction: string
{
    /** Decide about the plan: a subscription started or ended, or a trial ends with a way to pay on file. */
    case Plan = 'plan';

    /** Add or replace a way to pay: a card expires or was removed, or a trial ends without one. */
    case PaymentMethods = 'payment-methods';

    /** Look up the receipt of a payment that went through. */
    case Invoices = 'invoices';

    /** Settle a payment that failed or waits for the customer's confirmation. */
    case Recovery = 'recovery';

    /** See a meter that is about to use up what the plan includes. */
    case Usage = 'usage';

    /** The hub route that answers it. */
    public function route(): string
    {
        return 'billing.account.'.$this->value;
    }
}
