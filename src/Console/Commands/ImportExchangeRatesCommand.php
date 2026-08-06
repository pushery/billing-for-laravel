<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Pushery\Billing\Contracts\PublishesExchangeRates;
use Pushery\Billing\Exceptions\ExchangeRateFeedUnreadable;
use Pushery\Billing\Tax\EcbRateParser;
use Pushery\Billing\Tax\ExchangeRateImport;

/**
 * Fetch the central bank's published rates into the local store.
 *
 * ## This package ships no rates, and this is why it does not have to
 *
 * Which rate is correct is jurisdiction knowledge and the rules contradict each other, so a shipped figure
 * would be wrong for somebody by law. What ships instead is this: the numbers on your documents are the
 * ones you imported, from a publisher you can name, into your own database.
 *
 * ## Off unless asked, twice
 *
 * `billing.tax_exchange_rates.enabled` has to be on AND `currencies` has to list something. Two switches
 * rather than one because they are two decisions: whether to hold rates locally at all, and which
 * currencies you actually settle in. A package that inferred the second would dial out for currencies
 * nobody sells in — and a package that contacts a public service because it happened to be installed is an
 * unpleasant surprise in somebody else's application.
 *
 * ## The window overlaps on purpose
 *
 * It re-fetches `lookback_days` back rather than yesterday alone. A publisher revises, a run is missed, a
 * machine sleeps through a night. Importing is idempotent, so an overlap costs a few rows rewritten with
 * the same figures — while a one-day window turns any missed run into a permanent hole in the series, and
 * the reader answers a hole with the NEXT publication day's rate, which is a real figure for the wrong date.
 *
 * ## One observation, two rules
 *
 * The bank publishes a rate, not a rule. Its daily reference rate is the correct one both at a tax point
 * and at a period end, so each observation is stored under both — see {@see ExchangeRateImport}.
 */
final class ImportExchangeRatesCommand extends Command
{
    protected $signature = 'billing:exchange-rates:import {--from= : first day to fetch (YYYY-MM-DD)} {--to= : last day to fetch}';

    protected $description = 'Fetch published exchange rates from the central bank into the local store';

    public function handle(
        Repository $config,
        EcbRateParser $parser,
        ExchangeRateImport $import,
        PublishesExchangeRates $publisher,
    ): int {
        if ($config->get('billing.tax_exchange_rates.enabled') !== true) {
            $this->components->warn(
                'billing.tax_exchange_rates.enabled is off, so nothing was fetched. Turning it on is what '
                .'says this installation holds rates locally; until then the package refuses every '
                .'conversion rather than answering one.'
            );

            return self::SUCCESS;
        }

        $currencies = $config->get('billing.tax_exchange_rates.currencies', []);
        $currencies = is_array($currencies) ? array_values(array_filter($currencies, is_string(...))) : [];

        if ($currencies === []) {
            $this->components->warn(
                'billing.tax_exchange_rates.currencies is empty, so there is nothing to fetch. List the '
                .'currencies you receive money in — rates are stored in the direction the bank publishes '
                .'them, euro to each of these, and are never turned around.'
            );

            return self::SUCCESS;
        }

        [$from, $to] = $this->window($config);

        $stored = 0;

        foreach ($currencies as $currency) {
            // WHERE the rates come from is the publisher's to say, not this command's. It used to be a URL
            // template and the literal 'ECB' written in here, which meant an installation filing under a
            // different jurisdiction's rule could import rates and store them against a publisher they never
            // came from -- and that name is frozen onto settlement documents, where it is the evidence an
            // auditor uses to check a figure against a published table.
            $response = Http::timeout(30)->get(
                $publisher->seriesUrl($currency, $from->toDateString(), $to->toDateString()),
            );

            if (! $response->successful()) {
                // Reported and skipped rather than thrown, and only here. One currency's outage must not
                // discard the others already fetched — but it is never silent, because a currency that
                // quietly stopped importing is a series that grows a hole and answers it with a later day.
                $this->components->error(
                    $publisher->describe()." answered {$response->status()} for {$currency}; its rates for "
                    .'this window were not imported. The others were.'
                );

                continue;
            }

            try {
                $rates = $parser->parse($response->body());
            } catch (ExchangeRateFeedUnreadable $unreadable) {
                $this->components->error("{$currency}: {$unreadable->getMessage()}");

                continue;
            }

            $stored += $import->store($rates, ExchangeRateImport::CENTRAL_BANK_BASES, $publisher->sourceName());
        }

        $this->components->info(sprintf(
            'Imported %d exchange-rate row(s) for %s between %s and %s. Source: %s.',
            $stored,
            implode(', ', $currencies),
            $from->toDateString(),
            $to->toDateString(),
            $publisher->describe(),
        ));

        return self::SUCCESS;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(Repository $config): array
    {
        $to = is_string($this->option('to')) && $this->option('to') !== ''
            ? CarbonImmutable::parse($this->option('to'))
            : CarbonImmutable::now();

        if (is_string($this->option('from')) && $this->option('from') !== '') {
            return [CarbonImmutable::parse($this->option('from')), $to];
        }

        $days = $config->get('billing.tax_exchange_rates.lookback_days', 10);
        $days = is_int($days) && $days > 0 ? $days : 10;

        return [$to->subDays($days), $to];
    }
}
