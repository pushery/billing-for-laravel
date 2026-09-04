<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The configured fee-refund policy cannot exist under the configured supply regime.
 *
 * Retaining a commission on a refund presupposes a document the platform issued to the merchant for a
 * service — something that survives the sale being unwound. A commission chain has no such document: the
 * platform buys and resells, its turnover is the margin between two supplies, and unwinding the sale
 * unwinds both. Money kept afterwards sits on no supply at all.
 *
 * That is not a bookkeeping nicety. The retained amount would surface on a tax return as turnover the
 * platform cannot point at a document for, and the merchant would be short by an amount no invoice
 * explains — discovered, in both cases, by somebody auditing months of settled sales.
 *
 * Refused at preflight rather than at the first refund, because by then the sales it applies to are made.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class FeeRefundPolicyNotPermitted extends RuntimeException
{
    public static function retainInCommissionChain(): self
    {
        return new self(
            "The fee refund policy 'retain' is configured, but the supply regime is 'commission_chain'. In a ".
            'commission chain the platform buys and resells, so its turnover is the margin between two '.
            'supplies and there is no commission invoice a retained fee could sit on — unwinding the sale '.
            'unwinds both supplies. Set billing.marketplace.fee.refund_policy to "refund", or move to the '.
            'intermediation regime if the platform really does charge the merchant a commission it bills '.
            'separately.'
        );
    }

    public static function unknown(string $policy): self
    {
        return new self(
            "The fee refund policy '{$policy}' is not one this package knows. Use 'refund' (the platform ".
            "returns its commission in proportion to what was refunded) or 'retain' (the platform keeps it). ".
            'Refused rather than defaulted, because guessing here decides how much money a merchant gets '.
            'back on every refund.'
        );
    }
}
