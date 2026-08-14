<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\ReportingFilingRefused;
use Pushery\Billing\Marketplace\ReportingFilingRegister;
use Pushery\Billing\Models\ReportingExportRecord;
use Pushery\Billing\Models\ReportingFiling;

/**
 * Write down that a produced record actually went out — the line between "may be rebuilt" and "is fixed".
 *
 * ## It transmits nothing, and that is the design
 *
 * The package holds no portal credentials and submits nothing on anybody's behalf. What it can do is record
 * that an operator submitted THESE EXACT BYTES on this day, so that afterwards there is one answer to "what
 * did we report" rather than a rebuild that may no longer match. That is why filing takes an export record
 * and not a year: the bytes are the claim.
 *
 * ## Why filing is a separate command from producing
 *
 * Producing is repeatable and filing is not. A flag on the producing command would make an irreversible act
 * a character in a shell line — and the act is irreversible for a reason: once a period is filed, later
 * changes are a CORRECTION that names its predecessor, never a rewrite of what went out.
 */
final class ReportingFileCommand extends Command
{
    protected $signature = 'billing:reporting:file
        {year : the reporting period that was submitted}
        {--currency= : the currency of the period, defaults to billing.currency}
        {--by= : who submitted it — recorded verbatim on the filing}
        {--correction : record this as a correction of the period\'s latest filing}';

    protected $description = 'Record that a produced reporting record was actually submitted';

    public function handle(Repository $config, ReportingFilingRegister $register): int
    {
        if ($config->get('billing.marketplace.enabled', false) !== true) {
            $this->components->error('The marketplace is switched off, so there is no reporting period to file.');

            return self::FAILURE;
        }

        $year = (int) $this->argument('year');
        $currency = $this->currency($config);

        $export = ReportingExportRecord::query()
            ->where('period_year', $year)
            ->where('currency', $currency)
            ->latest('id')
            ->first();

        if (! $export instanceof ReportingExportRecord) {
            // Refused rather than produced on the spot. Filing says "these bytes went out", and bytes this
            // command generated a second ago went nowhere — recording them as submitted would be the one
            // lie the whole register exists to make impossible.
            $this->components->error(sprintf('No record has been produced for %d in %s yet. Run billing:reporting:run first.', $year, $currency));

            return self::FAILURE;
        }

        $by = $this->submittedBy();

        try {
            $filing = $this->option('correction') === true
                ? $register->fileCorrection($export, $this->latestOrFail($register, $year, $currency), $by)
                : $register->file($export, $by);
        } catch (ReportingFilingRefused $refusal) {
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s for %d in %s recorded, submitted by %s.',
            $filing->correction_sequence === 0 ? 'Filing' : 'Correction '.$filing->correction_sequence,
            $year,
            $currency,
            $by,
        ));

        return self::SUCCESS;
    }

    /**
     * The filing a correction corrects.
     *
     * Resolved here rather than defaulted inside the register, because "correct the latest" is this
     * command's convenience and not a rule of the register — which deliberately asks for the predecessor
     * by name so a correction can never attach itself to whatever happens to be newest.
     *
     * @throws ReportingFilingRefused when the period has nothing to correct
     */
    private function latestOrFail(ReportingFilingRegister $register, int $year, string $currency): ReportingFiling
    {
        $latest = $register->latestFilingFor($year, $currency);

        if (! $latest instanceof ReportingFiling) {
            throw ReportingFilingRefused::nothingToCorrect($year, $currency);
        }

        return $latest;
    }

    /**
     * Who submitted it, recorded verbatim.
     *
     * Falls back to the shell user rather than to a package-invented label: a filing is somebody's act, and
     * "system" on that column would name nobody at exactly the moment somebody has to be named.
     */
    private function submittedBy(): string
    {
        $option = $this->option('by');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $user = get_current_user();

        return $user === '' ? 'unknown' : $user;
    }

    private function currency(Repository $config): string
    {
        $option = $this->option('currency');

        if (is_string($option) && $option !== '') {
            return strtoupper($option);
        }

        $configured = $config->get('billing.currency', 'EUR');

        return strtoupper(is_string($configured) ? $configured : 'EUR');
    }
}
