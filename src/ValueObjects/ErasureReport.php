<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What an erasure actually did — because "erased" is a claim someone may one day have to substantiate
 * (GDPR Art. 5(2)), and because the retained half needs to be visible rather than quietly assumed.
 *
 * A person can stand on more than one axis: the buyer who holds a subscription, and the merchant on the other
 * side of a routed sale. `$purged` and `$retained` count every axis together, per table, so a receipt written
 * from them covers everything the erasure did. `$axes` holds the same counts per axis, for a receipt that has
 * to say which side of a sale a row was on.
 */
final readonly class ErasureReport
{
    /**
     * @param  array<string, int>  $purged  rows deleted (or scrubbed), per table, over every axis
     * @param  array<string, int>  $retained  rows kept but unlinked from the person, per table, over every axis
     * @param  array<string, int>  $unspentCredit  credit the customer still had, per currency: a debt
     * @param  array<string, array{purged: array<string, int>, retained: array<string, int>}>  $axes  the same counts, per axis
     */
    public function __construct(
        public array $purged,
        public array $retained,
        public array $unspentCredit = [],
        public array $axes = [],
    ) {}

    /** Whether anything at all belonged to this owner. */
    public function isEmpty(): bool
    {
        return array_sum($this->purged) === 0 && array_sum($this->retained) === 0;
    }
}
