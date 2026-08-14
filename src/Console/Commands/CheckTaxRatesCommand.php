<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Enums\RateChangeClass;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Tax\EuOssTaxCalculator;
use Pushery\Billing\Tax\RateChangeTriage;
use Pushery\Billing\Tax\RateImporter;
use Pushery\Billing\Tax\RateProposal;
use Pushery\Billing\Tax\TedbRateSource;

/**
 * Ask the source what the rates should be, and write down a PROPOSAL — never a change.
 *
 * ## The half that existed and could not be reached
 *
 * `billing:rates:probe` compares: it says THAT the shipped table differs from what the source publishes.
 * The work that turns that report into something an operator can act on — plausibility, triage against the
 * countries this installation has actually billed into, a reviewable proposal on disk — was built, tested,
 * and had no entry point at all. `RateImporter` had zero callers, which made the whole cluster behind it
 * unreachable from shipped code while every one of its own tests stayed green.
 *
 * ## It proposes. It does not apply.
 *
 * The proposal is written BESIDE the snapshot and the snapshot is never touched. That is a decision, not an
 * omission: a rate change is a legal statement about what a member state charges, and a package that edited
 * its own priced-from file on the strength of one HTTP response would be making that statement on the
 * operator's behalf. `RateChangeExclusions` says the same thing in the other direction — no mass rate
 * change ships.
 *
 * ## Three exit codes, and the third is the whole point
 *
 * 0 nothing to do · 1 a proposal was written · **2 the source could not be asked, or answered something
 * implausible**. Collapsing 2 into 1 is how a check becomes noise: a DNS failure reported as "your rates
 * are wrong" teaches an operator to dismiss the one signal that matters, and a real drift then arrives
 * looking exactly like the noise they learned to ignore.
 *
 * ## Off unless asked
 *
 * Same switch as the probe. Reaching a public service is not something a package should do because it
 * happened to be installed.
 */
final class CheckTaxRatesCommand extends Command
{
    protected $signature = 'billing:tax-rates:check {--on= : the date to ask about, default today}
        {--format=text : text or json}';

    protected $description = 'Ask the source for the current VAT rates and write a reviewable proposal';

    public function handle(RateImporter $importer): int
    {
        if (! (bool) Config::get('billing.tax_rate_probe.enabled', false)) {
            $this->components->warn(
                'The rate check is off. It reaches a public service, so it never runs unasked — set '
                .'BILLING_RATE_PROBE=1 (billing.tax_rate_probe.enabled) to enable it.'
            );

            return self::SUCCESS;
        }

        $on = CarbonImmutable::parse((string) ($this->option('on') ?: 'today'))->startOfDay();
        $answer = TedbRateSource::on($on);

        if ($answer->failure !== null) {
            $this->components->warn($answer->failure);

            return $importer->unreachable()->exitCode();
        }

        $proposal = $importer->propose($answer->rows, $answer->situationOn, $on, $on, EuOssTaxCalculator::shippedRatesBps());

        // Written only when there is something to review AND the answer is usable. A proposal file for an
        // unusable answer would be a file an operator opens, reads, and cannot act on — and next time does
        // not open.
        $written = $proposal->usable() && $proposal->hasChanges() ? $this->write($proposal) : null;

        $this->report($proposal, $written);

        return $proposal->exitCode();
    }

    /**
     * Where the proposal goes.
     *
     * Beside the snapshot by default, so the two share a dating convention and an operator can diff them
     * side by side. Configurable because that default lives inside the installed package: a deployment that
     * treats `vendor/` as read-only, or that would lose the file on the next release, points this at a
     * writable path instead.
     */
    private function write(RateProposal $proposal): string
    {
        $default = dirname(__DIR__, 3).'/resources/tax-rates';
        $configured = Config::get('billing.tax_rate_probe.proposal_path');
        $directory = is_string($configured) && $configured !== '' ? $configured : $default;
        $path = rtrim($directory, '/')."/proposal-{$proposal->situationOn}.json";

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        file_put_contents($path, json_encode($proposal->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function report(RateProposal $proposal, ?string $written): void
    {
        // JSON means JSON. A format a machine was promised and that carries a line of prose in the middle is
        // not a format — the nightly that files this proposal would have to strip it, and the first version
        // of this command made exactly that mistake. The written path travels IN the document instead, which
        // is also the only place a machine could use it.
        if ($this->option('format') === 'json') {
            $this->line(json_encode(
                [...$proposal->toArray(), 'written_to' => $written],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        if ($written !== null) {
            $this->components->info('Proposal written to '.$written);
        }

        if (! $proposal->usable()) {
            $this->components->warn('The answer is not usable, so no proposal was written.');

            foreach ($proposal->assessment['implausible'] as $note) {
                $this->components->twoColumnDetail('implausible', $note);
            }

            return;
        }

        if (! $proposal->hasChanges()) {
            $this->components->info('The source agrees with the rates in force.');

            return;
        }

        // Triaged against what this installation has actually billed into, which is what makes the list
        // worth reading: a rate move in a country nobody here sells to is recorded, not decided on.
        $classified = RateChangeTriage::classify($proposal->changes, $this->invoicedCountries(), $proposal->assessment);

        foreach ($proposal->changes as $country => $change) {
            $class = $classified[$country] ?? RateChangeClass::RecordedOnly;

            $this->components->twoColumnDetail(
                $country.'  '.$class->value,
                sprintf('%d → %d bps', $change['from'], $change['to']),
            );
        }
    }

    /**
     * The countries this installation has actually billed into.
     *
     * Read from issued documents rather than from configuration, because the question triage asks is what
     * happened, not what was allowed. An open market nobody ever sold into should not raise a prompt, and a
     * country billed before it was formally opened certainly should.
     *
     * @return list<string>
     */
    private function invoicedCountries(): array
    {
        $countries = [];

        foreach (InvoiceRecord::query()->whereNotNull('destination_country')->distinct()->pluck('destination_country') as $country) {
            if (is_string($country) && $country !== '') {
                $countries[] = $country;
            }
        }

        return $countries;
    }
}
