<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Http\Request;

/**
 * An event mapper that can tell from a delivery alone a second id the provider knows its payment by.
 *
 * A payment can go by two ids over its life. On Stripe a Checkout Session stands for the payment until the customer
 * tries to pay, and from then on its payment intent does: the intent's events name the intent, and the expiry of a
 * session that holds one is about the same payment. An application that moved on to the intent asks for the deliveries
 * about that payment by the intent, one that kept the session asks by the session, and the receiver records both ids
 * beside such a delivery, so either question finds it.
 *
 * The answer is read from the request alone, as {@see NamesThePayment::paymentReference()} is, and this lives apart
 * from that contract for the reason it gives: a method added to it would be a fatal error in every mapper a consumer
 * already wrote.
 */
interface NamesAnotherIdOfThePayment extends NamesThePayment
{
    /**
     * The provider's other id of the payment this request is about, beside the one `paymentReference()` names, or
     * null where the request names no other.
     */
    public function otherPaymentReference(Request $request): ?string;
}
