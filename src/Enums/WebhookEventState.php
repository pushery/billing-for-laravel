<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The operational state of a provider webhook delivery, and of each effect run against it.
 *
 *  - Pending  received (or claimed) and not finished yet — a worker may be on it right now.
 *  - Handled  finished successfully. Never re-run: a replay skips it.
 *  - Failed   the effect threw and its transaction rolled back, so it did NOT happen. It is RE-CLAIMABLE
 *             — the queue's retry and `billing:webhooks:replay` both pick it back up. This is the state
 *             an operator looks for: it is the work the package knows it still owes.
 */
enum WebhookEventState: string
{
    case Pending = 'pending';
    case Handled = 'handled';
    case Failed = 'failed';

    /** Whether a run in this state may be claimed and run (again). A handled run never may. */
    public function isReplayable(): bool
    {
        return $this !== self::Handled;
    }
}
