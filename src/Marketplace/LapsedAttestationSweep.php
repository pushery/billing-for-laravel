<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Pushery\Billing\Events\CreatorPlacedOnTaxHold;
use Pushery\Billing\Models\CreatorTaxStatusRecord;

/**
 * Finds the merchants whose attestation has quietly run out, and announces each one exactly once.
 *
 * ## Why a sweep and not an event
 *
 * A merchant reaches a tax hold two ways, and only one of them writes anything.
 *
 * Somebody **recording** a blocking standing is a write, and a write can be watched. An attestation
 * **expiring** is not: no row changes, nothing is dispatched, and `statusAt()` simply begins answering
 * `Unclarified` because a date passed. The hold starts by time alone — and that is precisely the route where
 * the merchant most needs telling, because they did nothing and can suddenly neither sell nor be paid.
 *
 * Nothing can observe that except something that goes looking.
 *
 * ## Why it announces once and not nightly
 *
 * The hold persists; the announcement does not repeat. A sweep that re-announced every night would turn the
 * one channel a merchant has into noise, and the message that finally matters would arrive looking exactly
 * like the sixty before it.
 *
 * The marker lives beside the series rather than in it: the intervals record what was declared and when,
 * while "has this person been told" is a fact about the telling. Writing the second into the first would
 * make an announcement look like a status change.
 */
final readonly class LapsedAttestationSweep
{
    public function __construct(private Dispatcher $events) {}

    /**
     * Announce every hold that began because an attestation lapsed.
     *
     * @return int how many merchants were told
     */
    public function announce(CarbonImmutable $now): int
    {
        $at = Carbon::instance($now);
        $announced = 0;

        $lapsed = CreatorTaxStatusRecord::query()
            ->whereNotNull('attested_until')
            ->where('attested_until', '<=', $at)
            ->whereNull('hold_announced_at')
            ->where('effective_from', '<=', $at)
            ->orderBy('id')
            ->get();

        foreach ($lapsed as $record) {
            // Only the interval that actually ANSWERS today. An older, superseded row may also carry a past
            // attestation date without being what `statusAt()` reads — announcing on that would tell a
            // merchant they are held when a later declaration already covers them.
            if (! $this->stillGoverns($record, $at)) {
                continue;
            }

            $merchant = $record->merchant;

            if ($merchant === null) {
                // The merchant is gone. Nothing to tell, and marking it keeps the sweep from re-examining
                // the row every night for a recipient that will never exist.
                $record->forceFill(['hold_announced_at' => $at])->save();

                continue;
            }

            $this->events->dispatch(new CreatorPlacedOnTaxHold($merchant, 'billing::tax_hold.attestation_lapsed'));

            // Marked after dispatching, not before. A crash between the two re-announces once, which is
            // recoverable; the other order loses the announcement entirely, which is not.
            $record->forceFill(['hold_announced_at' => $at])->save();

            $announced++;
        }

        return $announced;
    }

    /**
     * Whether this record is the one still answering for its merchant.
     *
     * A later interval for the same merchant supersedes it, and a merchant whose newer declaration is valid
     * is not on hold at all.
     */
    private function stillGoverns(CreatorTaxStatusRecord $record, Carbon $at): bool
    {
        return ! CreatorTaxStatusRecord::query()
            ->where('merchant_type', $record->merchant_type)
            ->where('merchant_id', $record->merchant_id)
            ->where('effective_from', '>', $record->effective_from)
            ->where('effective_from', '<=', $at)
            ->exists();
    }
}
