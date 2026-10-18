<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * The rate table of `billing.ai.rates`, and the one place that reads it.
 *
 * A model's entries are looked up by array access on the table, never through a dotted configuration key: model ids
 * carry dots (`gpt-5.4-mini`, `claude-haiku-4-5-20251001`), and `config('billing.ai.rates.gpt-5.4-mini')` would read a
 * key `gpt-5` with a child `4-mini`. Each model holds one entry or a list of entries with a `valid_from` date, so a price
 * change keeps the price that applied before it, and a call is priced at the rate of its own day.
 */
final readonly class ModelPricing
{
    public function __construct(private Repository $config) {}

    /**
     * The rate of a model on a day: the entry with the latest `valid_from` at or before it.
     *
     * @throws InvalidBillingConfig when the table has no rate for the model on that day, or an entry is malformed
     */
    public function for(string $model, ?CarbonInterface $at = null): ModelRate
    {
        $table = $this->config->get('billing.ai.rates');
        $entries = is_array($table) ? ($table[$model] ?? null) : null;

        if (! is_array($entries) || $entries === []) {
            throw InvalidBillingConfig::forKey('billing.ai.rates', "a rate for the model [{$model}]");
        }

        // One entry may stand alone; several stand as a list.
        $entries = array_is_list($entries) ? $entries : [$entries];
        $day = CarbonImmutable::instance($at ?? CarbonImmutable::now())->startOfDay();
        $applies = null;

        foreach ($entries as $entry) {
            $rate = $this->rateFrom($model, $entry);

            if ($rate->validFrom->lessThanOrEqualTo($day) && (! $applies instanceof ModelRate || $rate->validFrom->greaterThan($applies->validFrom))) {
                $applies = $rate;
            }
        }

        if (! $applies instanceof ModelRate) {
            throw InvalidBillingConfig::forKey('billing.ai.rates', "a rate for the model [{$model}] valid on {$day->toDateString()}");
        }

        return $applies;
    }

    private function rateFrom(string $model, mixed $entry): ModelRate
    {
        $key = "billing.ai.rates[{$model}]";

        if (! is_array($entry)) {
            throw InvalidBillingConfig::forKey($key, 'an array with input and output prices per million tokens');
        }

        $validFrom = $entry['valid_from'] ?? '1970-01-01';
        $currency = $entry['currency'] ?? $this->config->get('billing.ai.currency', 'USD');

        if (! is_string($validFrom) || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InvalidBillingConfig::forKey($key, 'a valid_from date and a three-letter currency');
        }

        $input = Micros::parse($entry['input'] ?? null, $key.'.input');
        $output = Micros::parse($entry['output'] ?? null, $key.'.output');

        return new ModelRate(
            model: $model,
            currency: $currency,
            validFrom: CarbonImmutable::parse($validFrom)->startOfDay(),
            input: $input,
            output: $output,
            cacheRead: isset($entry['cache_read']) ? Micros::parse($entry['cache_read'], $key.'.cache_read') : $input,
            cacheWrite: isset($entry['cache_write']) ? Micros::parse($entry['cache_write'], $key.'.cache_write') : $input,
            reasoning: isset($entry['reasoning']) ? Micros::parse($entry['reasoning'], $key.'.reasoning') : $output,
        );
    }
}
