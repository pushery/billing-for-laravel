<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;

/**
 * One observation as a publisher issued it, before anything decides what rule it answers under.
 *
 * Deliberately not a {@see FrozenExchangeRate}: that one carries a `basis`, which is the RULE a rate was
 * correct under, and a publisher does not state a rule. The central bank publishes a daily reference rate;
 * whether that is the rate at a tax point or the rate at a period end depends entirely on which question is
 * being asked of it later. Attaching a basis here would be the parser deciding a legal question.
 *
 * @param  string  $from  the currency one unit of which buys `rateScaled` units of `$to`
 * @param  string  $to  the quote currency
 * @param  int  $rateScaled  the published figure at eight decimal places, as an integer
 * @param  CarbonImmutable  $on  the date the PUBLISHER stated, read out of the document
 */
final readonly class PublishedRate
{
    public function __construct(
        public string $from,
        public string $to,
        public int $rateScaled,
        public CarbonImmutable $on,
    ) {}
}
