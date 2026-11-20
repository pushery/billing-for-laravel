<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\BaseInterestRates;

/**
 * The base rates the application lists in `billing.dunning_interest.base_rates`, each from the date it takes effect.
 *
 * The list maps a date, `Y-m-d`, to the rate in percent per year that holds from that day until the next date in the
 * list. A day before the first date has no known rate. An entry whose key is no such date, or whose rate is not a
 * number, is left out rather than read as something it does not say.
 */
final readonly class ConfiguredBaseInterestRates implements BaseInterestRates
{
    public function __construct(private Repository $config) {}

    public function on(DateTimeInterface $day): ?float
    {
        $wanted = $day->format('Y-m-d');
        $rate = null;
        $since = '';

        foreach ((array) $this->config->get('billing.dunning_interest.base_rates', []) as $from => $value) {
            $from = (string) $from;

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1 && is_numeric($value) && $from <= $wanted && $from > $since) {
                $since = $from;
                $rate = (float) $value;
            }
        }

        return $rate;
    }
}
