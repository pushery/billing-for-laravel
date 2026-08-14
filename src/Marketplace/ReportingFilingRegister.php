<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Pushery\Billing\Exceptions\ReportingFilingRefused;
use Pushery\Billing\Models\ReportingExportRecord;
use Pushery\Billing\Models\ReportingFiling;

/**
 * Which produced records went out, and in what order — the line between "may be rebuilt" and "is fixed".
 *
 * ## What filing changes
 *
 * Before a period is filed, {@see ReportingExport} may produce it as often as anybody likes; each run must
 * come back byte-identical, and a run that does not is the signal that something moved. After it is filed,
 * the figures that went out are settled. Producing the period again stays allowed and stays useful — it is
 * how divergence is DETECTED — but what comes back is a new record, and the only way it reaches an
 * authority is as a correction naming the filing it supersedes.
 *
 * Nothing here reaches back into what was filed. That is the property the whole chain is built for: an
 * export row cannot be edited, a filing row cannot be edited, and a period's history is therefore a list of
 * facts in the order they happened rather than a current state somebody has been maintaining.
 *
 * ## It transmits nothing
 *
 * The package holds no portal credentials. `file()` records that an operator submitted these exact bytes at
 * this moment; the submission itself happens outside, the same line {@see ReportingExportArchive} and
 * `FilingCalendar` draw. That is also why there is no column for the authority's own receipt: the package
 * never sees one, and a nullable column for a value that can never be filled in afterwards — the row is
 * immutable — would be a place to put nothing.
 *
 * ## Why a correction names its predecessor explicitly
 *
 * `fileCorrection()` takes the filing being corrected rather than looking it up. Resolving it silently
 * would file a correction against whatever happened to be latest, including a filing the caller had never
 * seen — and "correct the period" is exactly the instruction somebody issues from a stale screen. Naming it
 * makes the caller state which state of the world they are correcting, and the register refuses when that
 * is no longer the current one.
 */
final readonly class ReportingFilingRegister
{
    public function __construct(private ReportingExportArchive $archive) {}

    /**
     * File a produced record as the period's FIRST filing.
     *
     * @throws ReportingFilingRefused when the period already went out — which is over-reporting, not an
     *                                idempotent repeat, so it is refused rather than absorbed
     */
    public function file(ReportingExportRecord $export, string $filedBy, ?CarbonInterface $at = null): ReportingFiling
    {
        $existing = $this->latestFilingFor($export->period_year, $export->currency);

        if ($existing instanceof ReportingFiling) {
            throw ReportingFilingRefused::periodAlreadyFiled($export->period_year, $export->currency, $existing);
        }

        return $this->write($export, $filedBy, 0, null, $at);
    }

    /**
     * File a produced record as a correction of an earlier filing of the same period.
     *
     * @throws ReportingFilingRefused when the correction restates a different period, answers a filing that
     *                                has itself been corrected, or carries the same bytes as the filing it
     *                                claims to correct
     */
    public function fileCorrection(
        ReportingExportRecord $export,
        ReportingFiling $corrects,
        string $filedBy,
        ?CarbonInterface $at = null,
    ): ReportingFiling {
        if ($corrects->period_year !== $export->period_year || $corrects->currency !== $export->currency) {
            throw ReportingFilingRefused::correctsAnotherPeriod($export->period_year, $export->currency, $corrects);
        }

        $latest = $this->latestFilingFor($export->period_year, $export->currency);

        if ($latest instanceof ReportingFiling && $latest->getKey() !== $corrects->getKey()) {
            throw ReportingFilingRefused::correctsSupersededFiling($corrects, $latest);
        }

        // Compared by fingerprint, not by asking whether anything looks different. The bytes are what was
        // reported, so identical bytes mean the period says exactly what it already said — and sending that
        // again is another copy of the same report rather than a correction of it.
        if ($this->archive->matchesStored($corrects->export, $export->contents)) {
            throw ReportingFilingRefused::correctionChangesNothing($corrects);
        }

        return $this->write($export, $filedBy, $corrects->correction_sequence + 1, $corrects, $at);
    }

    /** The period's most recent filing — a correction if any were filed, otherwise the first one. */
    public function latestFilingFor(int $year, string $currency): ?ReportingFiling
    {
        return ReportingFiling::query()
            ->where('period_year', $year)
            ->where('currency', strtoupper($currency))
            ->orderByDesc('correction_sequence')
            ->first();
    }

    /**
     * Everything filed about a period, first filing first — the period's history rather than its state.
     *
     * @return Collection<int, ReportingFiling>
     */
    public function filingsFor(int $year, string $currency): Collection
    {
        return ReportingFiling::query()
            ->where('period_year', $year)
            ->where('currency', strtoupper($currency))
            ->orderBy('correction_sequence')
            ->get();
    }

    /**
     * Whether a freshly produced record differs from what the period last reported.
     *
     * This is the trigger for a correction, and it is deliberately a QUESTION rather than an alarm. A period
     * whose figures moved after filing is the ordinary consequence of a late refund, a corrected
     * classification or a seller's amended master data — the divergence is the expected outcome of those
     * events, not a defect in the run that produced them. What matters is that somebody sees it before the
     * deadline instead of learning about it from an authority.
     *
     * False when nothing has been filed yet: an unfiled period has nothing to correct, and there is no
     * reading of "needs a correction" that could be true.
     */
    public function needsCorrection(int $year, string $currency, string $contents): bool
    {
        $latest = $this->latestFilingFor($year, $currency);

        if (! $latest instanceof ReportingFiling) {
            return false;
        }

        return ! $this->archive->matchesStored($latest->export, $contents);
    }

    private function write(
        ReportingExportRecord $export,
        string $filedBy,
        int $sequence,
        ?ReportingFiling $corrects,
        ?CarbonInterface $at,
    ): ReportingFiling {
        return ReportingFiling::query()->create([
            'export_id' => $export->getKey(),
            'period_year' => $export->period_year,
            'currency' => $export->currency,
            'correction_sequence' => $sequence,
            'corrects_filing_id' => $corrects?->getKey(),
            'filed_at' => $at,
            'filed_by' => $filedBy,
        ]);
    }
}
