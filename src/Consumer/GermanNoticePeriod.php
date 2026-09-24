<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a consumer's ordinary cancellation of a recurring contract ends it under German law, at the longest notice the
 * law lets standard terms ask for.
 *
 * ## The rule
 *
 * § 309 Nr. 9 BGB lets standard terms hold a consumer to the initial term with at most one month's notice before it
 * ends (lit. c). A contract that then renews tacitly may renew only for an indefinite time, cancelable at any time at
 * one month's notice at most (lit. b). So a cancellation ends the contract at the end of the initial term when it
 * arrives at least a month before that, and a month after it arrives otherwise: late in the initial term, or at any
 * time after it. Both are one comparison, the later of the initial term's end and the notice's end.
 *
 * On a yearly subscription after its first year that is an end inside a paid year, which is what
 * {@see ProratedCancellation} ends the subscription at, paying the rest of the year back.
 *
 * ## A month, counted the way the law counts it
 *
 * The day the cancellation arrives does not count (§ 187 Abs. 1 BGB). The notice ends when the day with the same
 * number in the following month has passed, and when that month has no such day, with its last day (§ 188 Abs. 2
 * and 3 BGB): a cancellation that arrives on 31 January ends with 28 or 29 February.
 *
 * Counted in the time zone of the moment passed in, because the day a cancellation arrived on depends on it. Pass the
 * arrival in the time zone the contract is performed in.
 *
 * ## What it does not do
 *
 * It does not read the initial term off a subscription. Nothing in this package stores what a subscription was sold
 * as, and deriving it from a start date is wrong the moment a cycle was shifted or swapped, and wrong silently. The
 * caller knows what it sold, so the caller passes it.
 *
 * It answers for terms that ask for the full month the law allows. Terms with a shorter notice end earlier, and a
 * contract may always let a customer go sooner than it has to.
 */
final readonly class GermanNoticePeriod
{
    /**
     * The earliest moment a cancellation arriving at `$receivedAt` ends the contract.
     *
     * @param  CarbonInterface  $receivedAt  when the cancellation reached the business, in the contract's time zone
     * @param  CarbonInterface  $initialTermEndsAt  when the term the contract was first entered for ends
     */
    public static function earliestEnd(CarbonInterface $receivedAt, CarbonInterface $initialTermEndsAt): CarbonImmutable
    {
        $noticeEnds = CarbonImmutable::instance($receivedAt)->addMonthNoOverflow()->endOfDay();
        $termEnds = CarbonImmutable::instance($initialTermEndsAt);

        return $noticeEnds->greaterThan($termEnds) ? $noticeEnds : $termEnds;
    }
}
