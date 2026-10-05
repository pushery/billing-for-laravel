<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Closure;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Exceptions\ReportingRateMissing;
use Pushery\Billing\Models\InvoiceExchangeRate;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Money;

/**
 * A document's amounts in the currency a return is filed in, at the rate frozen onto the document for that.
 *
 * The reporting rate is the one {@see FreezeReportingRates} writes after the period closes: published for the
 * reporting currency, so "1 EUR = 11.0550 SEK" reads from EUR to SEK, and an amount in SEK is divided by it. The
 * rate itself is never inverted and stored; the division happens here, on each amount, at the reporting
 * currency's own precision and rounded half away from zero, which is where the return owns its rounding.
 *
 * A document in a currency other than the reporting one that carries no reporting rate is refused rather than
 * left out: a return without one of its sales reconciles with itself and with nothing that was sold.
 *
 * It names no layer of its own. The caller hands it the one layer its declaration converts at, and the refusal
 * that names the step which freezes it, so one conversion only ever reads one layer and two differently converted
 * amounts cannot meet in it.
 */
final readonly class ReportingConversion
{
    private string $reporting;

    /**
     * @param  ExchangeRateLayer  $layer  which frozen rate converts, the one the caller's declaration states
     * @param  Closure(string, string, string): ReportingRateMissing  $refusal  the refusal for a document without that
     *                                                                          rate, given its number, its currency and
     *                                                                          the reporting currency
     */
    public function __construct(string $reporting, private ExchangeRateLayer $layer, private Closure $refusal)
    {
        $this->reporting = strtoupper($reporting);
    }

    /** The currency every converted amount is in. */
    public function currency(): string
    {
        return $this->reporting;
    }

    /**
     * An amount of the document, in minor units of its own currency, in minor units of the reporting currency.
     *
     * @throws ReportingRateMissing when the document is in another currency and carries no reporting rate into this one
     */
    public function of(InvoiceRecord $document, int $minor): int
    {
        $currency = strtoupper((string) $document->currency);

        if ($currency === $this->reporting || $minor === 0) {
            return $minor;
        }

        $row = $document->exchangeRates->first(
            fn (InvoiceExchangeRate $rate): bool => $rate->layer === $this->layer
                && strtoupper($rate->from_currency) === $this->reporting
                && strtoupper($rate->to_currency) === $currency,
        );

        if (! $row instanceof InvoiceExchangeRate) {
            throw ($this->refusal)($document->number ?? '#'.$document->id, $currency, $this->reporting);
        }

        $amount = abs($minor) * 10 ** Money::exponentFor($this->reporting);
        $divisor = $row->rate_scaled * 10 ** Money::exponentFor($currency);
        $sign = $minor < 0 ? -1 : 1;

        // Exact while the dividend below fits an integer, the divisor added to it included, which holds for an
        // amount below some 460 million minor units. Beyond that the float's relative error stays far below half a
        // minor unit of the result.
        if ($amount <= intdiv(PHP_INT_MAX - $divisor, 2 * FrozenExchangeRate::SCALE)) {
            return $sign * intdiv(2 * $amount * FrozenExchangeRate::SCALE + $divisor, 2 * $divisor);
        }

        return $sign * (int) round($amount * FrozenExchangeRate::SCALE / $divisor);
    }
}
