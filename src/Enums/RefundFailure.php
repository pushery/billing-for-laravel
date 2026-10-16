<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a refund the provider accepted did not reach the customer.
 *
 * Both are final. The provider pays nothing out for either, and nothing starts the refund again by itself.
 */
enum RefundFailure: string
{
    /** The provider tried to pay it out and could not. */
    case Failed = 'failed';

    /** It was taken back before it was paid out, on the provider's dashboard or through its API. */
    case Canceled = 'canceled';
}
