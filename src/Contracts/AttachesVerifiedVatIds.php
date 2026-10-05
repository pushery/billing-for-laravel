<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\VatIdValidation;

/**
 * Hands the provider a customer's EU VAT number once the package has proven it valid.
 *
 * Stripe Tax applies the reverse charge to a cross-border sale to a business as soon as the customer carries a tax id
 * in the right format, whether or not anybody verified it. A checkout that asks the buyer for the number therefore
 * reverse-charges on whatever was typed, and the seller still owes the tax on an invalid one. This attaches a number
 * only after the bound `VatIdValidator` has proven it valid, so a checkout opened for the customer afterwards
 * reverse-charges on a proven number and on nothing else.
 *
 * A capability of the driver: bound where the provider keeps tax ids on its customers, and absent otherwise, so a
 * host asks the container whether it exists.
 */
interface AttachesVerifiedVatIds
{
    /**
     * Validate the number and, only once it is proven valid, attach it to the billable's customer at the provider.
     *
     * Returns the outcome of the validation. Anything but `Valid` attaches nothing, and the customer is then taxed
     * as any other customer is.
     */
    public function attach(Model $billable, string $vatId): VatIdValidation;

    /** Remove the number from the billable's customer, for a business whose number no longer holds. */
    public function detach(Model $billable, string $vatId): void;
}
