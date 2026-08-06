<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Marketplace\CreatorTaxStatusHold;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * The tax-standing hold has a date on which it starts, or it holds nobody.
 *
 * This point exists because of a specific way the configuration reads wrong. `blocks_sales` and
 * `blocks_payouts` both ship as `true`, which looks like two active enforcements — and with no enforcement
 * date, neither of them does anything at all. An operator reading the config file would have every reason
 * to believe merchants without a tax standing are being stopped.
 *
 * The date is not a way of leaving the hold off. It is how an established marketplace turns it on without
 * stopping everybody at once: a merchant nobody has declared for is `Unclarified`, and `Unclarified` is
 * exactly the state that blocks, so the day this begins is the day every creator who has not yet declared
 * stops selling. Picking a date and collecting declarations before it arrives is the whole point.
 *
 * **Waivable, and deliberately.** A platform whose merchants are all one known entity — a single-brand
 * marketplace, an internal one — has no standing to establish and no reason to be held. Waiving records
 * that as a decision rather than leaving the checklist permanently amber.
 *
 * **Not blocking**, for the same reason: a platform can go live without the hold, and the checklist's job
 * here is to make sure nobody does so believing it is on.
 */
final readonly class TaxStatusHoldCheckpoint implements GoLiveCheckpoint
{
    public function __construct(private CreatorTaxStatusHold $hold) {}

    public function key(): string
    {
        return 'configuration.tax_status_hold';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Configuration;
    }

    public function isBlocking(): bool
    {
        return false;
    }

    public function isWaivable(): bool
    {
        return true;
    }

    public function evaluate(): CheckpointOutcome
    {
        // An unreadable date throws out of here rather than being reported as unset, and that is the right
        // direction: the checklist is exactly where a typo in a tax control should surface, loudly, before
        // the first sale rather than during one.
        $from = $this->hold->enforcedFrom();

        if (! $from instanceof CarbonImmutable) {
            return CheckpointOutcome::fail(
                'The creator tax-status hold has no start date, so it holds nobody — whatever '
                .'billing.marketplace.tax_status_hold.blocks_sales and blocks_payouts say. Set '
                .'billing.marketplace.tax_status_hold.enforce_from to the day it should begin, far enough '
                .'out to collect declarations first: a merchant nobody has declared for is refused from that '
                .'day, and on an established marketplace that is most of them. Waive this if your merchants '
                .'have no standing to establish.'
            );
        }

        return CheckpointOutcome::pass(
            'The creator tax-status hold begins on '.$from->toDateString().'. From that day a routed sale '
            .'on behalf of a merchant with no recorded tax standing is refused before it reaches the '
            .'provider.'
        );
    }
}
