<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a recorded usage event stands on its way to the provider that bills it.
 *
 *  - Local     nothing to report — the tier does not bill this meter, so the event is a counter entry
 *              and an audit record only. It is NOT an error, and it must never be retried.
 *  - Pending   reportable and not yet accepted by the provider. The flusher owns it.
 *  - Reported  the provider has it and will bill it. Terminal.
 *  - Reversed  reported, then withdrawn before the invoice closed. Terminal.
 *  - Failed    the provider refused it past the retry budget, or the cycle it belongs to has closed.
 *              Terminal, and LOUD: this is revenue that will not be collected unless someone acts.
 */
enum UsageEventState: string
{
    case Local = 'local';
    case Pending = 'pending';
    case Reported = 'reported';
    case Reversed = 'reversed';
    case Failed = 'failed';

    /** Whether the flusher should still try to hand this event to the provider. */
    public function awaitsProvider(): bool
    {
        return $this === self::Pending;
    }
}
