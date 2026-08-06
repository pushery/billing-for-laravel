<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\ExchangeRateSource;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Exceptions\ExchangeRateUnavailable;
use Pushery\Billing\Models\InvoiceExchangeRate;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Puts a rate onto a document once, at the moment the document is made.
 *
 * ## Why this exists rather than a lookup at read time
 *
 * A rate looked up when somebody opens a document is the rate today, not the rate the sale was booked at.
 * The two differ, and the difference is exactly what an audit finds — with the added twist that the second
 * one looks perfectly reasonable. So the rate is part of the booking, frozen here, and every later reader
 * reads what was recorded rather than asking again.
 *
 * That includes corrections. A refund that re-derived the rate would reverse an amount nobody ever
 * declared: the original document states one figure, the reversal another, and the difference is a
 * currency movement rather than anything either party did.
 *
 * ## One rate per layer, and the layers are not interchangeable
 *
 * The document takes one rule, the reporting return another, the payout a third — see
 * {@see ExchangeRateLayer}. Freezing one and letting the others be derived would reintroduce the whole
 * problem for the two that were left out.
 *
 * ## It refuses rather than approximating, and that refusal is the caller's to handle
 *
 * If no rate is held for the day and rule asked for, {@see ExchangeRateSource} throws and this does not
 * catch it. Booking a document with a rate nobody published is worse than not booking it: the document
 * looks defensible and states a figure that cannot be held against the official series.
 */
final readonly class FreezeExchangeRateOnDocument
{
    public function __construct(private ExchangeRateSource $rates) {}

    /**
     * Refuse now if the rate this document will need is not held.
     *
     * Separate from freezing because of WHEN each has to happen. Freezing needs a document; the refusal has
     * to come BEFORE one exists — specifically before a document number is drawn, because a number spent on
     * a settlement that then fails is a gap somebody has to explain to an auditor later.
     *
     * The check and the freeze therefore ask the same question twice, deliberately. A database read is a
     * cheap price for not burning a number, and the alternative — freeze first, roll back on failure — puts
     * the rollback on the path where things are already going wrong.
     *
     * @throws ExchangeRateUnavailable
     */
    public function assertObtainable(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): void
    {
        $this->rates->rateFor($from, $to, $on, $basis);
    }

    /**
     * Freeze the rate for one layer onto one document.
     *
     * Idempotent by the store's own unique key: a document already carrying a rate for this layer keeps the
     * one it has. That is deliberate rather than convenient — re-freezing would silently replace a figure a
     * document has already been issued with, which is the thing this whole class exists to prevent.
     *
     * (This docblock sat above `assertObtainable` until 2026-07-28, one of two stacked there. PHP gives the
     * symbol the LAST block, so it documented nothing and `freeze` documented nothing either.)
     */
    public function freeze(
        InvoiceRecord $invoice,
        ExchangeRateLayer $layer,
        string $from,
        string $to,
        CarbonImmutable $on,
        ExchangeRateBasis $basis,
    ): InvoiceExchangeRate {
        $existing = InvoiceExchangeRate::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('layer', $layer->value)
            ->first();

        if ($existing instanceof InvoiceExchangeRate) {
            return $existing;
        }

        $rate = $this->rates->rateFor($from, $to, $on, $basis);

        return InvoiceExchangeRate::query()->create([
            'invoice_id' => $invoice->getKey(),
            'layer' => $layer->value,
            'from_currency' => $rate->fromCurrency,
            'to_currency' => $rate->toCurrency,
            'rate_scaled' => $rate->rateScaled,
            // The PUBLISHER's date, off the answer rather than off the request. A rate resolved forward to
            // the next publication day belongs to that day, and that is the day a reviewer looks it up under.
            'rate_date' => $rate->on->toDateString(),
            'basis' => $rate->basis->value,
            'source' => $rate->source,
        ]);
    }

    /**
     * Carry an original document's frozen rates onto a correction of it.
     *
     * This is the half that makes freezing worth doing. A rate recorded and never read back is
     * indistinguishable, in the database, from a rate recorded and read correctly — so until a correction
     * reads it, the table is documentation rather than a guard.
     *
     * COPIED, not looked up again, and not left off. The three options are not close:
     *
     * - Looking the rate up again dates the reversal to the day it was made. The original states one figure,
     *   the reversal another, and the difference is a currency movement that neither party caused. That is
     *   the failure the whole seam exists to prevent, arriving through the back door.
     * - Leaving it off makes the correction the only document in the chain that cannot say what it was
     *   converted at — and it is the one a reviewer reaches for first, because it is why the numbers moved.
     * - Copying gives the correction the original's rate, date, source and rule verbatim. It is
     *   self-contained: whoever reads it never has to traverse to the original to know what it means.
     *
     * Copying is also what this file's neighbors already do. `SettlementCorrectionIssuer` carries
     * `supply_regime` and `settlement_document_type` across for the same stated reason — a correction that
     * re-derived them would describe a transaction that did not happen.
     *
     * Idempotent per layer, so re-running a correction cannot rewrite a figure it has already been issued
     * with. Returns how many layers were carried, which is 0 for a document that never had a rate — the
     * ordinary single-currency case, and not an error.
     */
    public function carryTo(InvoiceRecord $original, InvoiceRecord $correction): int
    {
        $carried = 0;

        /** @var list<InvoiceExchangeRate> $frozen */
        $frozen = InvoiceExchangeRate::query()
            ->where('invoice_id', $original->getKey())
            ->get()
            ->all();

        foreach ($frozen as $rate) {
            $already = InvoiceExchangeRate::query()
                ->where('invoice_id', $correction->getKey())
                ->where('layer', $rate->layer->value)
                ->exists();

            if ($already) {
                continue;
            }

            InvoiceExchangeRate::query()->create([
                'invoice_id' => $correction->getKey(),
                'layer' => $rate->layer->value,
                'from_currency' => $rate->from_currency,
                'to_currency' => $rate->to_currency,
                'rate_scaled' => $rate->rate_scaled,
                'rate_date' => $rate->rate_date,
                'basis' => $rate->basis->value,
                'source' => $rate->source,
            ]);

            $carried++;
        }

        return $carried;
    }
}
