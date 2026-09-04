<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A mandate was asked for through a seam that promises one synchronously, by a driver that cannot.
 *
 * `PaymentRails::createMandate()` returns a non-nullable MandateReference, which is a promise: after this
 * call, a mandate exists. Stripe keeps it. Mollie cannot — a mandate there is born when the customer
 * COMPLETES a redirect on Mollie's own checkout, and depending on what they do it may never be born.
 *
 * Refusing is the only one of the three available answers that is not a defect. Returning the payment id
 * instead would be stored as a mandate, charged against on the next cycle, refused by Mollie, and read as
 * the subscriber's payment failing — a wrong answer that looks like a right one for as long as it takes
 * somebody to investigate a dunning ladder nobody earned. Blocking until the webhook arrives would hold an
 * HTTP request open for however long the customer takes to decide.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MandateNeedsRedirect extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(
            "The {$driver} driver cannot create a mandate synchronously. A mandate exists there only once ".
            'the customer has COMPLETED a first payment on the provider\'s checkout — until then there is a '.
            'payment in progress and no mandate, and there may never be one. Start the redirect flow and '.
            'store the mandate when the webhook reports the first payment paid.'
        );
    }

    /**
     * The redirect flow itself could not start, because the provider named no checkout.
     *
     * A handshake with nowhere to send the customer is not a partial success: it would put an empty href
     * on a button and lose the subscriber at the one step the whole flow exists for.
     */
    public static function noCheckoutReturned(string $driver, string $payment): self
    {
        return new self(
            "The {$driver} driver started a first payment ({$payment}) but the provider returned no ".
            'checkout to send the customer to, so the mandate flow cannot begin. This is a provider-side '.
            'refusal rather than a configuration error — most often the requested method is not enabled '.
            'on the account, or it cannot establish a mandate at all.'
        );
    }

    public static function tokenizationUnsupported(string $driver): self
    {
        return new self(
            "The {$driver} driver has no tokenization step. Payment details are captured on the provider's ".
            'own checkout, so there is no raw payment data for a server to exchange for a token — a driver '.
            'that answered this would be inventing one. Use the redirect flow instead.'
        );
    }
}
