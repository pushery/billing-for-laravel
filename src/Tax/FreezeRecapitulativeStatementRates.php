<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\SuppliesRecapitulativeStatementExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Exceptions\ReportingPeriodNotClosed;
use Pushery\Billing\Models\InvoiceExchangeRate;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * Freezes the recapitulative statement's rate onto a closed period's reverse-charged sales in another currency.
 *
 * The statement states each sale in the currency it is filed in, and a sale issued in SEK reaches it only at a
 * rate. Which rate is the profile's answer ({@see SuppliesRecapitulativeStatementExchangeRateBasis}); in Germany
 * it is the ministry's average for the month the supply was made, so the date asked for is that month and not
 * the end of the period.
 *
 * It runs once the period has ended, for the reason {@see FreezeReportingRates} gives: asked early, the rate
 * reader resolves forward to the next publication and would freeze a real, checkable, wrong figure. It is
 * idempotent the same way, and never revisits a document that has a statement rate already.
 *
 * A profile that names no rule freezes nothing, and the statement then refuses a sale in another currency.
 */
final readonly class FreezeRecapitulativeStatementRates
{
    public function __construct(
        private FreezeExchangeRateOnDocument $freezer,
        private CheckpointRegistry $profiles,
        private Repository $config,
    ) {}

    /**
     * Freeze the statement rate for every reverse-charged sale in another currency issued in the period.
     *
     * @param  CarbonImmutable  $periodStart  first day of the period, inclusive
     * @param  CarbonImmutable  $periodEnd  last day of the period, inclusive
     * @param  CarbonImmutable  $now  the moment the run happens, so the refusal below is testable
     * @return int how many documents were given a rate they did not have
     *
     * @throws ReportingPeriodNotClosed when the period has not ended yet
     */
    public function forPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $now): int
    {
        if ($now->startOfDay()->lessThanOrEqualTo($periodEnd->startOfDay())) {
            throw ReportingPeriodNotClosed::on($periodEnd);
        }

        $profile = $this->profiles->profile();

        if (! $profile instanceof SuppliesRecapitulativeStatementExchangeRateBasis) {
            return 0;
        }

        $basis = $profile->recapitulativeStatementExchangeRateBasis();
        $statement = $this->config->get('billing.currency');
        $statement = is_string($statement) && $statement !== '' ? strtoupper($statement) : 'EUR';

        $documents = InvoiceRecord::model()::query()
            ->where('reverse_charge', true)
            ->whereBetween('issued_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->whereNotNull('currency')
            ->where('currency', '!=', $statement)
            ->lazyById();

        $frozen = 0;

        foreach ($documents as $document) {
            if (! $document->isUnionReverseChargeSale()) {
                continue;
            }

            $already = InvoiceExchangeRate::model()::query()
                ->where('invoice_id', $document->getKey())
                ->where('layer', ExchangeRateLayer::RecapitulativeStatement->value)
                ->exists();

            if ($already) {
                continue;
            }

            $this->freezer->freeze(
                $document,
                ExchangeRateLayer::RecapitulativeStatement,
                $statement,
                (string) $document->currency,
                $this->supplyDateOf($document),
                $basis,
            );

            $frozen++;
        }

        return $frozen;
    }

    /**
     * The day the supply was made, which decides the month whose rate converts it.
     *
     * The delivery where the document names one, the end of the service period where it names that, and the issue
     * date otherwise. A correction converts at the month of the supply it corrects, so a reduction takes back the
     * amount that was reported rather than the same sale at a later month's rate.
     */
    private function supplyDateOf(InvoiceRecord $document): CarbonImmutable
    {
        $corrected = $document->isCorrection() ? $document->correctedIssuedAt() : null;

        // The query reads issued documents only, so the issue date always answers last; `now()` only satisfies
        // the type of a column that is nullable for drafts.
        return CarbonImmutable::instance(
            $document->delivered_on ?? $document->service_period_end ?? $corrected ?? $document->issued_at ?? CarbonImmutable::now(),
        );
    }
}
