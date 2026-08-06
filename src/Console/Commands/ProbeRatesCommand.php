<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Pushery\Billing\Tax\RateConformityProbe;
use Pushery\Billing\Tax\RateConformityReport;
use Throwable;

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

    private const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/tedb/ws/VatRetrievalService';

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

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
                ->send('POST', self::ENDPOINT, ['body' => $this->envelope($on)]);

            if ($response->successful()) {
                $report = $probe->compare($this->rowsIn($response->body()), $this->situationIn($response->body()), $on, $on);
            } else {
                // SAY WHICH. `unreachable: true` on its own is unfalsifiable: a refused connection,
                // a 500 from the service and a malformed request all arrive here and all read the
                // same in the report. Measured 2026-08-03 — three nightly runs reported unreachable
                // while the endpoint answered in 0.2 s from a laptop (405 to GET, 500 to a POST it
                // could not parse), and two separate diagnoses of "network problem" were wrong
                // because this line never said otherwise.
                $this->components->warn(sprintf(
                    'The rate source answered HTTP %d, so nothing was compared. First 200 bytes: %s',
                    $response->status(),
                    str_replace(["\n", "\r"], ' ', mb_substr($response->body(), 0, 200)),
                ));

                $report = $probe->unreachable();
            }
        } catch (Throwable $e) {
            // A transport failure is not a conformity failure. Reported as unreachable so the exit code
            // stays honest about what was actually learned, which is nothing.
            $this->components->warn('Could not reach the rate source: '.$e->getMessage());

            $report = $probe->unreachable();
        }

        $this->report($report);

        return $report->exitCode();
    }

    /** The SOAP envelope TEDB expects, built by hand so the package needs no ext-soap. */
    private function envelope(CarbonImmutable $on): string
    {
        $date = $on->toDateString();

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            .'xmlns:urn="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService">'
            .'<soap:Body><urn:retrieveVatRates><urn:memberStates/>'
            ."<urn:from>{$date}</urn:from><urn:to>{$date}</urn:to>"
            .'</urn:retrieveVatRates></soap:Body></soap:Envelope>';
    }

    /**
     * The rate rows in a response.
     *
     * @return list<array{memberState: string, type: string, rateType: string, value: float}>
     */
    private function rowsIn(string $body): array
    {
        $document = new DOMDocument;

        if (! @$document->loadXML($body)) {
            return [];
        }

        $rows = [];

        foreach ($document->getElementsByTagName('vatRateResults') as $node) {
            $rows[] = [
                'memberState' => $this->textIn($node, 'memberState'),
                'type' => $this->textIn($node, 'type'),
                // Asked directly: `rateTypeIn` already answers '' for a row that carries no rate element,
                // and the reduction drops anything that is not DEFAULT. A pre-check here would only make the
                // fallback below unreachable — a branch that cannot run is a branch nobody can trust.
                'rateType' => $this->rateTypeIn($node),
                'value' => (float) $this->textIn($node, 'value'),
            ];
        }

        return $rows;
    }

    /** The date the response says it is answering for — verified against the window by the probe. */
    private function situationIn(string $body): string
    {
        return preg_match('/<situationOn>([^<]+)<\/situationOn>/', $body, $m) === 1 ? $m[1] : '';
    }

    private function rateTypeIn(DOMElement $node): string
    {
        foreach ($node->getElementsByTagName('rate') as $rate) {
            return $this->textIn($rate, 'type');
        }

        return '';
    }

    private function textIn(DOMElement $node, string $tag): string
    {
        foreach ($node->getElementsByTagName($tag) as $child) {
            return trim($child->textContent);
        }

        return '';
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
