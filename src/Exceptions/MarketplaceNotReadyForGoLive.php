<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\ValueObjects\PreflightLine;
use RuntimeException;

/**
 * The marketplace switch is on while the go-live checklist still has open blocking points.
 *
 * The state this removes is a marketplace that starts on half a configuration. Nothing about it looks
 * wrong from the outside — sales complete, receipts render — and the damage is in the records: money routed
 * under terms that were never published, turnover booked in a country the operator never registered in.
 * Both are corrected by hand, transaction by transaction, if they are noticed at all.
 *
 * The message names every open point, because this is thrown at boot: an application that refuses to start
 * has also taken away the command that would explain why, so the refusal has to carry the explanation.
 */
final class MarketplaceNotReadyForGoLive extends RuntimeException
{
    /** @param  list<PreflightLine>  $blockers */
    public static function withOpenBlockers(array $blockers): self
    {
        $lines = array_map(
            static fn (PreflightLine $line): string => "  - [{$line->key}] {$line->reason}",
            $blockers,
        );

        return new self(
            "billing.marketplace.enabled is true, but the go-live checklist has open blocking points:\n".
            implode("\n", $lines)."\n".
            'Run `php artisan billing:marketplace:preflight` after switching the marketplace back off to see '.
            'the full checklist, close the points above, then enable it again.'
        );
    }
}
