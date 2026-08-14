<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Tax\RateConformityProbe;
use Pushery\Billing\Tax\RateConformityReport;
use Pushery\Billing\Tax\TedbRateSource;

/**
 * Ask the source whether the rates we ship are still what it publishes.
 *
 * ## Off unless asked
 *
 * Reaching a public service is not something a package should do because it happened to be installed. The
 * command refuses unless `BILLING_RATE_PROBE=1` is set, so a bare checkout, a fork, and every CI run that
 * did not intend this stay silent.
 *
 * ## Three exit codes, and the third is the whole point
 *
 * 0 agreement · 1 a difference was found · **2 the source could not be asked**. Collapsing 2 into 1 is how a
 * probe becomes noise: a DNS failure reported as "your rates are wrong" teaches an operator to dismiss the
 * one signal that matters, and then a real drift arrives looking exactly like the noise they learned to
 * ignore.
 *
 * ## No ext-soap
 *
 * TEDB speaks SOAP, but a hand-built envelope over an ordinary HTTP POST is enough and keeps the package
 * free of an extension a consumer would otherwise have to install for a job they may never run.
 */
final class ProbeRatesCommand extends Command
{
    protected $signature = 'billing:rates:probe {--format=text : text or json} {--on= : the date to ask about, default today}';

    protected $description = 'Compare the shipped VAT rates against the published source';

    public function handle(RateConformityProbe $probe): int
    {
        if (! (bool) Config::get('billing.tax_rate_probe.enabled', false)) {
            $this->components->warn(
                'The rate probe is off. It reaches a public service, so it never runs unasked — set '
                .'BILLING_RATE_PROBE=1 (billing.tax_rate_probe.enabled) to enable it.'
            );

            return self::SUCCESS;
        }

        $on = CarbonImmutable::parse((string) ($this->option('on') ?: 'today'))->startOfDay();

        // One reader for the source, shared with the proposing command next door. Two hand-written parsers
        // over one response would drift silently — a parser that drifts does not throw, it returns fewer
        // rows, and the caller then reports "no drift" over a response it failed to read.
        //
        // SAY WHICH, on a failure. `unreachable: true` on its own is unfalsifiable: a refused connection, a
        // 500 from the service and a malformed request all arrive here and all read the same in the report.
        // Measured 2026-08-03 — three nightly runs reported unreachable while the endpoint answered in 0.2 s
        // from a laptop (405 to GET, 500 to a POST it could not parse), and two separate diagnoses of
        // "network problem" were wrong because nothing ever said otherwise.
        $answer = TedbRateSource::on($on);

        if ($answer->failure !== null) {
            $this->components->warn($answer->failure);

            $report = $probe->unreachable();
        } else {
            $report = $probe->compare($answer->rows, $answer->situationOn, $on, $on);
        }

        $this->report($report);

        return $report->exitCode();
    }

    private function report(RateConformityReport $report): void
    {
        if ($this->option('format') === 'json') {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        if ($report->unreachable) {
            $this->components->warn('The source could not be asked, so nothing was learned about the rates.');

            return;
        }

        foreach ($report->drift as $country => $pair) {
            $this->components->error(
                "{$country}: we ship {$pair['shipped']} basis points, the source says {$pair['source']}."
            );
        }

        foreach ($report->refused as $country => $values) {
            $this->components->warn(
                "{$country}: the source gave more than one standard rate (".implode(', ', $values).'), '
                .'so there is nothing to compare against.'
            );
        }

        if ($report->missingFromSource !== []) {
            $this->components->info(
                'Not covered by the source: '.implode(', ', $report->missingFromSource)
                .' — silence there is not agreement.'
            );
        }

        if ($report->agrees()) {
            $this->components->info('The shipped rates match the source.');
        }
    }
}
