<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A buyer's tax ID turned out not to be registered.
 *
 * The charge is reversed on a tax ID's format, before the register has been asked, so a sale can have gone out
 * without tax under a number the register does not know, and then the platform can owe the tax it did not charge.
 * `reverseChargeInvoices` holds the provider references of this owner's invoices issued with the charge reversed
 * under that number, which are the ones to look at again. It can be empty: a one-off checkout without an invoice
 * record can have gone out under the number too.
 */
final readonly class TaxIdVerificationFailed implements BillingDomainEvent
{
    /**
     * @param  list<string>  $reverseChargeInvoices
     */
    public function __construct(
        public Model $owner,
        public string $type,
        public string $value,
        public array $reverseChargeInvoices,
    ) {}
}
