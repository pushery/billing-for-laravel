<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How far one refund intent got.
 *
 * `Pending` is written before the provider is called and is therefore also the state a crashed or
 * timed-out attempt is left in — which is correct rather than untidy. A pending row is not "nothing
 * happened"; it is "we do not know", and the provider's idempotency key on that same row is what makes
 * asking again safe.
 */
enum RefundAttemptStatus: string
{
    /** Recorded, and the outcome is unknown — including a call that may already have succeeded. */
    case Pending = 'pending';

    /** The provider confirmed it. */
    case Succeeded = 'succeeded';

    /** The provider refused it. A different intent needs a different row. */
    case Failed = 'failed';
}
