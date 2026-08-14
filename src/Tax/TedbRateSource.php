<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asking TEDB what the standard rates are on a given day.
 *
 * ## Why this is its own class
 *
 * Two commands need the same answer for opposite purposes: one COMPARES what we ship against the source,
 * the other PROPOSES a change from it. The envelope, the POST, the row extraction and the `situationOn`
 * reading are identical for both, and identical code written twice is two places one of them can be fixed.
 *
 * The specific way that hurts here is quiet. A parser that drifts does not throw; it returns fewer rows, or
 * the same rows with a field missing, and the caller reports "no change" over a response it failed to read.
 * One reader means the comparing half and the proposing half can never disagree about what the source said.
 *
 * ## No ext-soap
 *
 * TEDB speaks SOAP, but a hand-built envelope over an ordinary HTTP POST is enough and keeps the package
 * free of an extension a consumer would otherwise have to install for a job they may never run.
 *
 * ## Saying WHICH failure
 *
 * A failed ask reports its reason rather than a bare "unreachable". That distinction was paid for once
 * already: three nightly runs reported unreachable while the endpoint answered in 0.2 s from a laptop, and
 * two separate diagnoses of "network problem" were wrong because nothing said otherwise.
 */
final readonly class TedbRateSource
{
    private const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/tedb/ws/VatRetrievalService';

    /**
     * @param  list<array{memberState: string, type: string, rateType: string, value: float}>  $rows
     * @param  string  $situationOn  the date the source says it answered for, '' when it said nothing
     * @param  ?string  $failure  why the ask produced nothing, or null when it succeeded
     */
    private function __construct(
        public array $rows,
        public string $situationOn,
        public ?string $failure = null,
    ) {}

    /** Ask the source for the rates in force on one day. */
    public static function on(CarbonImmutable $day): self
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
                ->send('POST', self::ENDPOINT, ['body' => self::envelope($day)]);
        } catch (Throwable $e) {
            // A transport failure is not a rate finding. Reported as a failure so whatever the caller
            // returns stays honest about what was actually learned, which is nothing.
            return new self([], '', 'Could not reach the rate source: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return new self([], '', sprintf(
                'The rate source answered HTTP %d. First 200 bytes: %s',
                $response->status(),
                str_replace(["\n", "\r"], ' ', mb_substr($response->body(), 0, 200)),
            ));
        }

        return new self(self::rowsIn($response->body()), self::situationIn($response->body()));
    }

    /** The SOAP envelope TEDB expects, built by hand so the package needs no ext-soap. */
    private static function envelope(CarbonImmutable $on): string
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
    private static function rowsIn(string $body): array
    {
        $document = new DOMDocument;

        if (! @$document->loadXML($body)) {
            return [];
        }

        $rows = [];

        foreach ($document->getElementsByTagName('vatRateResults') as $node) {
            $rows[] = [
                'memberState' => self::textIn($node, 'memberState'),
                'type' => self::textIn($node, 'type'),
                // Asked directly: `rateTypeIn` already answers '' for a row that carries no rate element,
                // and the reduction drops anything that is not DEFAULT. A pre-check here would only make the
                // fallback below unreachable — a branch that cannot run is a branch nobody can trust.
                'rateType' => self::rateTypeIn($node),
                'value' => (float) self::textIn($node, 'value'),
            ];
        }

        return $rows;
    }

    /** The date the response says it is answering for — verified against the window by the caller. */
    private static function situationIn(string $body): string
    {
        return preg_match('/<situationOn>([^<]+)<\/situationOn>/', $body, $m) === 1 ? $m[1] : '';
    }

    private static function rateTypeIn(DOMElement $node): string
    {
        foreach ($node->getElementsByTagName('rate') as $rate) {
            return self::textIn($rate, 'type');
        }

        return '';
    }

    private static function textIn(DOMElement $node, string $tag): string
    {
        foreach ($node->getElementsByTagName($tag) as $child) {
            return trim($child->textContent);
        }

        return '';
    }
}
