<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Contracts\PaysOutstandingInvoices;
use Pushery\Billing\Enums\OutstandingPaymentOutcome;

/**
 * The outcome of {@see PaysOutstandingInvoices::payOutstanding()}: how the attempt ended, for which invoice, and
 * what the owner does next where it did not end paid.
 */
final readonly class OutstandingPayment
{
    public function __construct(
        public OutstandingPaymentOutcome $outcome,
        /** The provider's invoice the payment was for. Null where nothing was owed. */
        public ?string $invoiceReference = null,
        /** The provider's page on which the cardholder confirms the payment, where the outcome asks for that. */
        public ?string $confirmationUrl = null,
        /** Why the method was declined, in the provider's terms: the bank's decline code, else the provider's code. */
        public ?string $failureCode = null,
        /** The provider's words for the decline. */
        public ?string $failureMessage = null,
    ) {}
}
