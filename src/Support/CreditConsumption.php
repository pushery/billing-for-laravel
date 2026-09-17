<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Models\CreditLedgerEntry;
use Pushery\Billing\ValueObjects\ConsumedCredit;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\Money;

/**
 * Which credits a spend actually consumed, FIFO, derived from the ledger rather than stored beside it.
 *
 * ## The question, and why anything has to answer it
 *
 * A balance is fungible. A customer holding a paid top-up and a proration credit spends "balance",
 * and the ledger records one negative entry for it. The two are different things in the books
 * though: a top-up is money somebody paid, a proration credit is consideration being given back. So
 * before either half can be booked, an offset against the mixed balance has to be split.
 *
 * ## DERIVED, never stored, and that is the load-bearing decision
 *
 * An allocation table would be a second record of something the log already determines completely —
 * and a second record is a record that can disagree with the first. This ledger is append-only for
 * exactly that reason: `CreditLedgerEntry` mints its reference from its own id, "the one identifier
 * that cannot later be edited to point somewhere else". A stored allocation would reintroduce the
 * editable middle the append-only design removed.
 *
 * Replaying is cheap here in the way that matters: a balance's whole history is one indexed read,
 * and this runs when something is booked rather than on every page.
 *
 * ## FIFO, and the reason is not neatness
 *
 * Oldest credit first. It is deterministic, it is explainable to a customer looking at their own
 * balance, and it makes a reversal exact: a returned offset gives back the same lots its offset took,
 * because reversing the replay of an append-only log reaches the same answer every time. Anything
 * proportional would split a single invoice's correction across several tax periods for no reason
 * anybody could state afterwards.
 *
 * ## The negative balance is a real state, and it is answered rather than refused
 *
 * A balance can go negative — `debit()` says so in its own docblock, a refund claws back what the
 * customer already spent. So a spend can exceed every credit before it. What is consumed is then
 * everything there was, and the remainder belongs to no credit at all: it is reported as consumed
 * from nothing rather than invented onto the oldest lot, because booking a correction against an
 * invoice for money that was never in the balance is the failure this whole split exists to prevent.
 */
final readonly class CreditConsumption
{
    /**
     * The credits one spend consumed, oldest first.
     *
     * @param  list<CreditLedgerEntry>  $entries  every entry of one owner and currency, oldest first
     * @return list<ConsumedCredit> empty when the entry is not a spend, or when nothing was available
     */
    public static function of(CreditLedgerEntry $spend, array $entries): array
    {
        if ($spend->amount_minor >= 0) {
            // Not a spend. Answered rather than refused: a caller walking a whole ledger should not
            // have to filter first, and "this movement consumed nothing" is true of a credit.
            return [];
        }

        $remaining = -$spend->amount_minor;
        $lots = [];

        foreach ($entries as $entry) {
            if ($entry->id >= $spend->id) {
                // The spend consumes what existed BEFORE it. Its own entry and everything after are
                // not available to it, and a later credit is not retroactively spent by an earlier
                // offset — which is what makes a reversal give back the same lots.
                break;
            }

            if ($entry->amount_minor > 0) {
                $lots[] = ['entry' => $entry, 'left' => $entry->amount_minor];

                continue;
            }

            // An earlier spend already took from the front of the queue. Replaying it here is what
            // keeps this honest: without it, two spends would each be told they consumed the same
            // credit, and the same invoice would be corrected twice.
            $earlier = -$entry->amount_minor;

            foreach ($lots as $index => $lot) {
                if ($earlier <= 0) {
                    break;
                }

                $taken = min($lot['left'], $earlier);
                $lots[$index]['left'] -= $taken;
                $earlier -= $taken;
            }
        }

        $consumed = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            if ($lot['left'] <= 0) {
                continue;
            }

            $taken = min($lot['left'], $remaining);
            $remaining -= $taken;

            $entry = $lot['entry'];

            $consumed[] = new ConsumedCredit(
                $entry->reason,
                Money::of($taken, $entry->currency),
                $entry->source_type !== null && $entry->source_id !== null
                    ? new CreditSource($entry->source_type, $entry->source_id)
                    : null,
            );
        }

        return $consumed;
    }

    /**
     * How much of one spend came from credits the payer never paid for — the proration share.
     *
     * The figure a § 17 correction is measured in: money given back out of an already invoiced and
     * already taxed consideration. A spend of paid top-ups corrects nothing, because nothing about
     * the original sale changed.
     *
     * @param  list<ConsumedCredit>  $consumed
     */
    public static function unpaidShare(array $consumed, string $currency): Money
    {
        $minor = 0;

        foreach ($consumed as $credit) {
            if (! $credit->reason->booksAgainstMoney()) {
                $minor += $credit->amount->minorUnits;
            }
        }

        return Money::of($minor, $currency);
    }
}
