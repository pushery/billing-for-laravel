<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Http\Request;

/**
 * An event mapper that can tell from a delivery alone which payment it is about.
 *
 * The receiver records the answer beside the delivery, under an index. A delivery to a payment account has no owner
 * the package knows, because the person who paid is the application's customer, not the package's, so an erasure or an
 * export that looks for an owner never reaches it. An application that keeps the payments of its accounts finds the
 * deliveries about one of them by the payment instead.
 *
 * The answer is read from the request alone, without a call to the provider. So a delivery whose mapping fails is
 * named all the same, and a delivery recorded before the receiver named payments is named later, from its stored
 * payload.
 *
 * This lives beside {@see WebhookEventMapper} rather than on it for the reason {@see DerivesDeliveryKey} gives: that
 * contract is public, and a method added to it would be a fatal error in every mapper a consumer already wrote.
 */
interface NamesThePayment
{
    /**
     * The provider's id of the payment this request is about, the way the change the mapper reports for it names the
     * payment, or null where the request names none.
     */
    public function paymentReference(Request $request): ?string;
}
