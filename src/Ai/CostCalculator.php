<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use Carbon\CarbonInterface;

/**
 * What a model call cost: its tokens, bucket by bucket, at the model's rate of the day.
 *
 * Five buckets, each at its own price: the input that touched no cache, the input read from the cache, the input
 * written to it, the output, and the reasoning within the output. Summed before they are divided, so a call is
 * rounded once and not once per bucket.
 */
final readonly class CostCalculator
{
    public function __construct(private ModelPricing $pricing) {}

    /** What a call to the model cost, at the rate that applied on the day of the call. */
    public function costOf(string $model, TokenUsage $usage, ?CarbonInterface $at = null): AiCost
    {
        return self::price($usage, $this->pricing->for($model, $at));
    }

    /** What the tokens cost at a rate. A pure function: the same tokens at the same rate cost the same. */
    public static function price(TokenUsage $usage, ModelRate $rate): AiCost
    {
        $perMillion = $usage->uncachedInput() * $rate->input
            + $usage->cacheRead * $rate->cacheRead
            + $usage->cacheWrite * $rate->cacheWrite
            + $usage->plainOutput() * $rate->output
            + $usage->reasoning * $rate->reasoning;

        return new AiCost(intdiv($perMillion + 500_000, 1_000_000), $rate->currency);
    }
}
