<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use Carbon\CarbonImmutable;

/**
 * What a model's tokens cost from a date on, per million tokens of each bucket, in millionths of the currency.
 *
 * A price of 0.25 per million input tokens is 250 000 here, so a rate reads as integers and a cost below one cent is
 * still a cost. A bucket the provider does not price on its own carries the price of its parent: cache reads and
 * writes the input price, reasoning the output price.
 */
final readonly class ModelRate
{
    public function __construct(
        public string $model,
        public string $currency,
        public CarbonImmutable $validFrom,
        public int $input,
        public int $output,
        public int $cacheRead,
        public int $cacheWrite,
        public int $reasoning,
    ) {}
}
