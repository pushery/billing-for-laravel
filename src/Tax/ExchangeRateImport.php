<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Models\ExchangeRateRecord;

/**
 * Puts published observations into the local store, idempotently.
 *
 * ## Why one central-bank observation becomes two rows
 *
 * The store keys a rate by the RULE it answers under, and the central bank's daily reference rate answers
 * two of them: the rate at the tax point, and the rate at a period end. They are the same published figure
 * — what differs is which DAY each rule tells you to ask for, and that is the reader's business, not the
 * publisher's.
 *
 * Writing both here rather than teaching the reader to fall back between them is the deliberate half. A
 * fallback would mean a lookup silently answering under a rule the caller did not ask for, which is exactly
 * the substitution this whole seam refuses everywhere else. Two rows cost a few bytes and keep every answer
 * traceable to a row somebody imported under that rule.
 *
 * They are written in ONE operation, so the pair cannot drift: there is no path here that updates one basis
 * and leaves the other holding last week's figure.
 *
 * ## Idempotent, and re-importing is expected rather than tolerated
 *
 * A scheduled import overlaps its own history by design — the publisher revises, a run is missed, a range
 * is re-fetched by hand. So this upserts on the store's unique key. A second run over the same period
 * changes nothing and reports nothing new; a revised figure replaces the old one, which is correct, because
 * the publisher's current statement is the one a document must be defensible against.
 */
final readonly class ExchangeRateImport
{
    /**
     * The rules a central bank's daily reference rate answers.
     *
     * Both, because the bank publishes a rate and not a rule. Listed rather than derived from the enum: a
     * future basis must be an explicit decision about which series answers it, not something that starts
     * being written here because somebody added a case.
     *
     * @var list<ExchangeRateBasis>
     */
    public const array CENTRAL_BANK_BASES = [
        ExchangeRateBasis::CentralBankAtTaxPoint,
        ExchangeRateBasis::CentralBankAtPeriodEnd,
    ];

    /**
     * Store observations under every rule they answer.
     *
     * @param  list<PublishedRate>  $rates
     * @param  list<ExchangeRateBasis>  $bases  the rules these observations answer
     * @return int how many rows were written or refreshed
     */
    public function store(array $rates, array $bases, string $source): int
    {
        $written = 0;

        foreach ($rates as $rate) {
            foreach ($bases as $basis) {
                // Matched with `whereDate`, exactly as the reader matches. `updateOrCreate` on a plain
                // equality was the obvious first version and it never found an existing row: `rate_date`
                // casts to a date, which round-trips through the model's datetime format, so the value in
                // the column and the value in the where-clause disagreed by a midnight. Every second import
                // then hit the unique constraint instead of updating — measured, not theorized.
                //
                // Sharing the reader's lookup shape is the point rather than a workaround: a writer that
                // matched differently from the reader would be a slow way to discover the same thing again
                // on an engine where the two happened to agree.
                $existing = ExchangeRateRecord::query()
                    ->where('from_currency', $rate->from)
                    ->where('to_currency', $rate->to)
                    ->where('basis', $basis->value)
                    ->whereDate('rate_date', $rate->on->toDateString())
                    ->first();

                if ($existing instanceof ExchangeRateRecord) {
                    $existing->forceFill(['rate_scaled' => $rate->rateScaled, 'source' => $source])->save();
                } else {
                    ExchangeRateRecord::query()->create([
                        'from_currency' => $rate->from,
                        'to_currency' => $rate->to,
                        'rate_date' => $rate->on->toDateString(),
                        'basis' => $basis->value,
                        'rate_scaled' => $rate->rateScaled,
                        'source' => $source,
                    ]);
                }

                $written++;
            }
        }

        return $written;
    }
}
