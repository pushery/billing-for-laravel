<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Who publishes the rates this installation imports, and where they are fetched from.
 *
 * ## Why the publisher is a seam and not a constant
 *
 * Which rate is the correct one is jurisdiction knowledge, and the rules contradict each other across
 * jurisdictions — the sibling {@see SuppliesExchangeRateBasis} exists for exactly that reason. The
 * PUBLISHER is the other half of the same fact: a German document's conversion is correct under the finance
 * ministry's monthly average, an OSS return under the central bank's quarter-end reference rate, and a
 * payout under whatever the bank actually gave. Those are three publishers, not one.
 *
 * The importer had one of them written into it — a URL template and the literal string `'ECB'` — so an
 * installation that files under a different rule could import rates, store them against a source they did
 * not come from, and freeze that name onto a settlement document. The name on the document is evidence: it
 * is what an auditor reads to know which published table a figure can be checked against.
 *
 * ## What an implementation owes
 *
 * A URL that returns the publisher's own series for one currency against the reporting currency, and the
 * name that series is published under. Nothing about parsing: the format belongs to the parser, and a
 * publisher that speaks a different one needs its own, which is a larger change than swapping this.
 *
 * The package ships the central bank's implementation and binds it, so an installation that never thinks
 * about this keeps exactly the behavior it had.
 */
interface PublishesExchangeRates
{
    /**
     * Where one currency's series is fetched from.
     *
     * @param  string  $currency  the currency being quoted, uppercase ISO-4217
     * @param  string  $from  first day of the window, `Y-m-d`
     * @param  string  $to  last day of the window, `Y-m-d`
     */
    public function seriesUrl(string $currency, string $from, string $to): string;

    /**
     * The name this publisher's rates are stored and frozen under.
     *
     * Short and stable — it ends up in a column and on a document, and a name that changed between imports
     * would split one publisher's series into two that no query joins back together.
     */
    public function sourceName(): string;

    /** How a failure to reach this publisher is described to an operator, in that operator's words. */
    public function describe(): string;
}
