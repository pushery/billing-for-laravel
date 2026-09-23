<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The kinds of document a host can attach when it answers a dispute.
 *
 * Each kind says what the document proves, and a driver files it where its provider expects that proof. A
 * document that fits none of them is `Other`.
 */
enum DisputeDocument: string
{
    /** The receipt or invoice the buyer was given. */
    case Receipt = 'receipt';

    /** Correspondence with the buyer, such as the emails about the order. */
    case CustomerCommunication = 'customer_communication';

    /** A signature the buyer gave, on delivery for instance. */
    case CustomerSignature = 'customer_signature';

    /** Proof that a service was provided, such as a usage log or a completion record. */
    case ServiceDocumentation = 'service_documentation';

    /** Proof of shipment or delivery. */
    case ShippingDocumentation = 'shipping_documentation';

    /** The refund policy as the buyer was shown it. */
    case RefundPolicy = 'refund_policy';

    /** The cancellation policy as the buyer was shown it. */
    case CancellationPolicy = 'cancellation_policy';

    /** Proof that a charge the buyer calls a duplicate was a separate purchase. */
    case DuplicateChargeDocumentation = 'duplicate_charge_documentation';

    /** Anything else that supports the case. */
    case Other = 'other';
}
