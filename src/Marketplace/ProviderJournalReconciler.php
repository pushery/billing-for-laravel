<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Events\Dispatcher;
use Pushery\Billing\Contracts\ReportsMovedShares;
use Pushery\Billing\Events\ProviderJournalDrift;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\MovedShare;

/**
 * The package's own record of what it paid merchants, checked against the provider that actually paid them.
 *
 * ## What this adds to the reconciliation that already existed
 *
 * `CollectiveAccountReconciler` compares the exported booking batch against the rows that produced it, which
 * is a real check across a real seam — it catches a document the export drops, an account resolved
 * differently, a direction marker written the wrong way round, and it runs on every batch this package
 * emits. It is NOT superseded by this class and must not be read as such: what it cannot see is the
 * provider, because it is still the package against itself. A denormalized balance against
 * the signed sum of its own entries can only disagree under concurrency or a partial failure, so run
 * serially it passes forever — and a criterion that always passes is indistinguishable from one that is not
 * wired up.
 *
 * Two ledgers that CAN disagree is the marketplace's canonical failure, and it is quiet: a reversal raised in
 * the provider's dashboard, a transfer the provider adjusted, a webhook that never arrived. Every total on
 * our side still adds up. The merchant's does not.
 *
 * ## Per transfer, never per balance
 *
 * See `ProviderJournalDrift` for why comparing against the connected account's balance produces an alarm
 * that fires on every payout and is switched off within a week.
 *
 * ## Which side wins
 *
 * The provider is authoritative for what MOVED; this package is authoritative for what was OWED. A drift is
 * therefore repaired by correcting the local row — never by transferring again to match the journal, which
 * would move real money to settle a bookkeeping argument. This reconciler only ever READS; it writes no row
 * and moves no money, so that decision stays with a human who can see the case.
 *
 * ## The fee entries this depends on, and why they had to come first
 *
 * A comparison that did not know about the provider's own processing and dispute fees would find a
 * difference on every single sale, which is the shape of alarm that gets muted. Those are recorded
 * separately (`ProviderFee`) and are deliberately NOT part of this comparison: they are charged to the
 * platform, not deducted from the transfer, so subtracting them here would invent a drift instead of
 * finding one.
 */
final readonly class ProviderJournalReconciler
{
    public function __construct(
        private ReportsMovedShares $provider,
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Walk the journal and report every charge whose transfer the provider describes differently.
     *
     * @param  string  $provider  the driver name whose rows are being audited; a tree that has run two
     *                            providers holds rows only one of them can answer for
     * @param  int  $limit  a bound on one sweep, so an operator can run this against a large journal without
     *                      it becoming an unbounded provider round trip
     * @return list<ProviderJournalDrift>
     */
    public function sweep(string $provider, int $limit = 500): array
    {
        $rows = MerchantCharge::query()
            ->where('provider', $provider)
            ->whereNotNull('transfer_reference')
            // An erased merchant's figures are gone by design; asking the provider about them would both
            // fail and undo the point of erasing them.
            ->whereNull('merchant_erased_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $finding = $this->check($row);

            if ($finding instanceof ProviderJournalDrift) {
                $findings[] = $finding;
                $this->events?->dispatch($finding);
            }
        }

        return $findings;
    }

    private function check(MerchantCharge $row): ?ProviderJournalDrift
    {
        $reference = (string) $row->transfer_reference;

        // What WE believe the merchant kept: what the provider reported moving, less every reversal we know
        // about. `transfer_moved_minor` first, because a transfer that moved a different amount than was
        // owed is exactly the case being looked for.
        //
        // NULL IS NOT ZERO, and reading it as zero is the mass false alarm this whole design exists to
        // avoid. A destination charge moves the merchant's share as part of the PAYMENT and makes no
        // transfer call, so no figure is ever reported — while the provider still creates a transfer off
        // the charge, whose reference the row carries. Every such row, and every row settled before the
        // column existed, would report a drift of the full transfer value. The model states it in as many
        // words, and `MerchantCharge::reversibleMinor()` takes the same fallback for the same reason.
        $ours = new Money(
            ($row->transfer_moved_minor ?? $row->net_minor) - $row->transfer_reversed_minor,
            $row->currency
        );

        $merchant = $row->merchant;

        if ($merchant === null) {
            // A row whose merchant is gone cannot carry a finding anybody can act on. Skipped rather than
            // reported with a null subject, which would make the count look worse than the problem.
            return null;
        }

        $theirs = $this->provider->movedShare($reference);

        if (! $theirs instanceof MovedShare) {
            return new ProviderJournalDrift(
                $merchant,
                (string) $row->charge_reference,
                $reference,
                ProviderJournalDrift::MISSING_AT_PROVIDER,
                $ours,
                null,
            );
        }

        if ($theirs->moved->currency !== $row->currency) {
            // Reported before the amounts are compared, because comparing minor units across two currencies
            // is not a smaller finding — it is a meaningless one, and Money refuses it outright.
            return new ProviderJournalDrift(
                $merchant,
                (string) $row->charge_reference,
                $reference,
                ProviderJournalDrift::CURRENCY_DIFFERS,
                $ours,
                $theirs->net(),
            );
        }

        if ($theirs->net()->minorUnits === $ours->minorUnits) {
            return null;
        }

        return new ProviderJournalDrift(
            $merchant,
            (string) $row->charge_reference,
            $reference,
            ProviderJournalDrift::AMOUNT_DIFFERS,
            $ours,
            $theirs->net(),
        );
    }
}
