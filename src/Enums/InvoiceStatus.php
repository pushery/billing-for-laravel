<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * A provider-neutral invoice status. Each driver maps its own status vocabulary onto these (Stripe
 * draft/open/paid/uncollectible/void; a locally-generated invoice sets them directly),
 * so views never branch on provider strings.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Uncollectible = 'uncollectible';
    case Void = 'void';
    case Refunded = 'refunded';

    /** The WireKit badge intent for this status (color AND label — never color alone). */
    public function badgeIntent(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Open => 'info',
            self::Uncollectible => 'danger',
            self::Refunded => 'warning',
            self::Draft, self::Void => 'neutral',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Whether this is a settled end state a provider invoice does not leave. Stripe's invoice lifecycle is
     * draft -> open -> (paid | void | uncollectible); it never returns to open. So a terminal status must
     * not be regressed by a late or retried finalized webhook that still carries the earlier open status.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Void, self::Uncollectible], true);
    }

    /** Whether the invoice still owes money (open or uncollectible). */
    public function isOutstanding(): bool
    {
        return $this === self::Open || $this === self::Uncollectible;
    }
}
