<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MandateHandshake;
use Pushery\Billing\ValueObjects\Money;

/**
 * A driver whose mandates are established by the CUSTOMER completing a redirect, not by a server call.
 *
 * {@see PaymentRails::createMandate()} returns a non-nullable reference, which is a promise: after that
 * call, a mandate exists. Some providers keep it — a stored card is attached synchronously. Others cannot:
 * the mandate is born when the customer completes a first payment on the provider's own checkout, and
 * depending on what they do it may never be born at all.
 *
 * A driver in the second group implements THIS instead, and refuses the synchronous seam. That refusal is
 * the point: of the three answers available to a driver that cannot keep the promise, two are defects.
 * Inventing a reference (the payment id, say) gets it stored, charged against on the next cycle, refused,
 * and read as the subscriber failing to pay — a wrong answer that looks right until somebody investigates
 * a dunning ladder nobody earned. Blocking until the webhook arrives holds a request open for as long as
 * the customer takes to decide.
 *
 * It is a SIBLING contract rather than a method on `PaymentRails`, and that is not tidiness: `PaymentRails`
 * has implementations outside this package, so appending a method is a fatal error in code the package does
 * not own.
 */
interface EstablishesMandateByRedirect
{
    /**
     * Begin establishing a mandate, returning where to send the customer.
     *
     * @param  Money  $verification  The first payment's amount. Providers require a real charge to establish
     *                               a mandate; it is normally the smallest unit the currency has, and it is
     *                               the caller's decision rather than the driver's because it appears on the
     *                               customer's statement.
     */
    public function beginMandate(string $customerReference, Money $verification, string $returnUrl): MandateHandshake;
}
