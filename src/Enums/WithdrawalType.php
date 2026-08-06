<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which cancellation regime a sale falls under.
 *
 * It follows from what was sold rather than from a setting, because the buyer's right does: a file they
 * already downloaded, a subscription they have partly consumed, and a service performed for them once are
 * three different situations, and treating them alike gets one of them wrong in the buyer's favor and
 * another in the seller's.
 */
enum WithdrawalType: string
{
    /** The right ends once delivery starts, after the buyer has agreed to that in advance. */
    case ExtinguishedOnDelivery = 'extinguished_on_delivery';

    /** Cancellable, with the part already consumed paid for pro rata. */
    case ProRataOnCancellation = 'pro_rata_on_cancellation';

    /** A service: cancellable until it has been performed. */
    case ServicePerformed = 'service_performed';

    /** An ordinary refund window, because nothing has been consumed yet. */
    case PlainRefundWindow = 'plain_refund_window';

    /** No right arises — the parties are not a business and a consumer. */
    case NotApplicable = 'not_applicable';
}
