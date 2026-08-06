<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\SuppliesReportingExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Exceptions\ReportingPeriodNotClosed;
use Pushery\Billing\Models\InvoiceExchangeRate;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * Freezes the reporting-layer rate onto a period's documents, once the period has actually ended.
 *
 * ## Why this cannot happen when the document is issued
 *
 * The document layer is frozen at issue, because its rate exists then. The reporting layer cannot be, and
 * the reason is arithmetic rather than architectural: the rule converts at the LAST DAY OF THE PERIOD, and
 * on the day a sale is booked that day has not happened. There is no rate to freeze, and no amount of
 * wiring makes one appear.
 *
 * That is why this is a separate pass with its own moment rather than another call inside the engine. It
 * runs after the period closes, walks the documents that were issued in it, and gives each the figure its
 * return will actually be filed on.
 *
 * ## It refuses to run early, loudly
 *
 * Asked for a period that has not ended, this throws instead of doing its best. The alternative is worse
 * than it looks: the rate reader resolves a missing day FORWARD to the next publication day, so an early
 * run would not fail — it would quietly freeze the first rate published after the request and stamp it
 * with that day's date. Every document in the period would then carry a real, checkable, wrong figure.
 *
 * ## Idempotent, and it never revisits a document that has one
 *
 * Re-running is expected: a period is closed once but a run can be interrupted, and a document can arrive
 * late through a correction. {@see FreezeExchangeRateOnDocument::freeze()} keeps whatever a document
 * already carries, so a second pass adds the stragglers and leaves every earlier figure untouched.
 */
final readonly class FreezeReportingRates
{
    public function __construct(
        private FreezeExchangeRateOnDocument $freezer,
        private CheckpointRegistry $profiles,
        private Repository $config,
    ) {}

    /**
     * Freeze the reporting rate for every convertible document issued in the period.
     *
     * @param  CarbonImmutable  $periodStart  first day of the period, inclusive
     * @param  CarbonImmutable  $periodEnd  last day of the period, inclusive — the day the rule converts at
     * @param  CarbonImmutable  $now  the moment the run happens, so the refusal below is testable
     * @return int how many documents were given a rate they did not have
     *
     * @throws ReportingPeriodNotClosed when the period has not ended yet
     */
    public function forPeriod(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $now,
    ): int {
        if ($now->startOfDay()->lessThanOrEqualTo($periodEnd->startOfDay())) {
            throw ReportingPeriodNotClosed::on($periodEnd);
        }

        $profile = $this->profiles->profile();

        // No rule declared, no rate frozen — the same answer the document layer gives, for the same reason.
        // Filing conversion is the filer's regime, and picking one for an installation that named none would
        // be this package deciding a jurisdictional question on its behalf.
        if (! $profile instanceof SuppliesReportingExchangeRateBasis) {
            return 0;
        }

        $basis = $profile->reportingExchangeRateBasis();

        $reporting = $this->config->get('billing.currency');
        $reporting = is_string($reporting) && $reporting !== '' ? strtoupper($reporting) : 'EUR';

        $frozen = 0;

        /** @var list<InvoiceRecord> $documents */
        $documents = InvoiceRecord::query()
            ->whereBetween('issued_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->whereNotNull('currency')
            ->where('currency', '!=', $reporting)
            ->get()
            ->all();

        foreach ($documents as $document) {
            $already = InvoiceExchangeRate::query()
                ->where('invoice_id', $document->getKey())
                ->where('layer', ExchangeRateLayer::Reporting->value)
                ->exists();

            if ($already) {
                continue;
            }

            $this->freezer->freeze(
                $document,
                ExchangeRateLayer::Reporting,
                $reporting,
                (string) $document->currency,
                $periodEnd,
                $basis,
            );

            $frozen++;
        }

        return $frozen;
    }
}
