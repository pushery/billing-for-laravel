<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Pushery\Billing\Dunning\ConfiguredBaseInterestRates;

/**
 * The base rate default interest is charged above, as it stood on a given day.
 *
 * The rate is a fact of the law the application bills under, and it changes: the German base rate under § 247 BGB
 * moves on 1 January and 1 July. The package cannot know it, so the application keeps it. The default reads the dated
 * list in `billing.dunning_interest.base_rates` ({@see ConfiguredBaseInterestRates}); an application that takes the
 * rate from elsewhere binds its own.
 */
interface BaseInterestRates
{
    /**
     * The base rate in percent per year on that day, or null where none is known for it.
     *
     * A day with no known rate earns no interest: charging one from a guessed rate would put a wrong amount on a
     * document.
     */
    public function on(DateTimeInterface $day): ?float;
}
