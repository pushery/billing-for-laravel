<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\ReportingNotPlausible;
use Pushery\Billing\Marketplace\ReportingExport;
use Pushery\Billing\Marketplace\ReportingFilingRegister;
use Pushery\Billing\Models\ReportingFiling;

/**
 * Produce a reporting period's official record — and refuse to, while anything about it is implausible.
 *
 * ## Why this command exists, and what it is NOT
 *
 * `ReportingExport` and `ReportingFilingRegister` had no internal caller, and the unreferenced-class
 * register said in as many words that this was the design rather than a gap: WHEN a return is prepared is
 * the host application's decision, and the package transmits nothing, so it cannot know a period went out.
 * That reasoning stands, and this command does not contradict it.
 *
 * What it adds is a **shipped** operator path. Without one, every adopter writes the same three calls —
 * produce, check what came back, record the submission — and each of them decides afresh whether the
 * plausibility refusal is fatal and whether a second filing is a correction. Those are answers this package
 * already has; leaving them to be re-derived per install is how they get derived differently.
 *
 * ## The plausibility check is not a warning
 *
 * It runs first and it BLOCKS. A period with open findings produces no export at all, because an export is
 * evidence of what was reported: producing one and then telling somebody not to send it leaves a file that
 * looks exactly like a filed record.
 *
 * ## Producing is not filing, and they are separate commands
 *
 * An export may be rebuilt as often as anybody likes; nothing is fixed until it is filed. Filing is the
 * operator's act — this package holds no portal credentials and transmits nothing — so it gets its own
 * verb rather than a flag on this one. A flag would make the irreversible step a character.
 */
final class ReportingRunCommand extends Command
{
    protected $signature = 'billing:reporting:run
        {year : the reporting period, as a calendar year}
        {--currency= : the currency to report in, defaults to billing.currency}';

    protected $description = 'Produce the official record for a reporting period, refusing while it is implausible';

    public function handle(Repository $config, ReportingExport $export, ReportingFilingRegister $register): int
    {
        if (! $this->marketplaceIsOn($config)) {
            $this->components->error('The marketplace is switched off, so no reporting period exists to produce a record for.');

            return self::FAILURE;
        }

        $year = (int) $this->argument('year');
        $currency = $this->currency($config);

        try {
            $record = $export->produce($year, $currency);
        } catch (ReportingNotPlausible $refusal) {
            // The findings are the output here, not the failure. An operator needs to know WHICH rule is
            // open before they can clear it, and the exception already names them all — re-wording that
            // would be a second list that drifts from the first.
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Produced the %d record for %s: %s v%s, %d seller(s), %d bytes.',
            $year,
            $currency,
            $record->format,
            $record->format_version,
            $record->seller_count,
            strlen($record->contents),
        ));

        $filing = $register->latestFilingFor($year, $currency);

        if ($filing instanceof ReportingFiling) {
            // Said out loud, because it changes what the operator does next. A period that is already filed
            // does not take a second filing — it takes a CORRECTION, which names its predecessor.
            $this->components->warn(sprintf(
                'This period was already filed on %s. A further submission is a correction, not a filing.',
                $filing->filed_at->toDateString(),
            ));

            return self::SUCCESS;
        }

        $this->components->info('Nothing is fixed yet. Record the submission with billing:reporting:file once it has actually gone out.');

        return self::SUCCESS;
    }

    /**
     * The currency to report in.
     *
     * A reporting period is per currency, because the amounts in it are sums of money and money in two
     * currencies does not add. Defaulting to the configured one is convenience for the overwhelming case
     * of an operator with a single one; naming it is how the other case is served.
     */
    private function currency(Repository $config): string
    {
        $option = $this->option('currency');

        if (is_string($option) && $option !== '') {
            return strtoupper($option);
        }

        $configured = $config->get('billing.currency', 'EUR');

        return strtoupper(is_string($configured) ? $configured : 'EUR');
    }

    private function marketplaceIsOn(Repository $config): bool
    {
        return $config->get('billing.marketplace.enabled', false) === true;
    }
}
