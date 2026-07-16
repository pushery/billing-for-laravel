<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What an erasure actually did — because "erased" is a claim someone may one day have to substantiate
 * (GDPR Art. 5(2)), and because the retained half needs to be visible rather than quietly assumed.
 */
final readonly class ErasureReport
{
    /**
     * @param  array<string, int>  $purged  rows deleted (or scrubbed), per model
     * @param  array<string, int>  $retained  rows kept but unlinked from the owner, per model
     * @param  array<string, int>  $unspentCredit  credit the customer still had, per currency: a debt
     */
    public function __construct(
        public array $purged,
        public array $retained,
        public array $unspentCredit = [],
    ) {}

    /** Whether anything at all belonged to this owner. */
    public function isEmpty(): bool
    {
        return array_sum($this->purged) === 0 && array_sum($this->retained) === 0;
    }
}
