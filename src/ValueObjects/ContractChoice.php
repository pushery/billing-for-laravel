<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Models\Subscription;

/**
 * One contract a person can choose on a cancellation or a withdrawal page.
 *
 * `key` is what the page writes as the option's value and posts back. It tells one contract from another without
 * naming anything internal, and the package asks the person's contracts again for it rather than trusting it.
 * `name` is how the contract is named on the page and in the kept declaration, so it is both what the person picks
 * from and what the confirmation repeats.
 *
 * `initialTermEndsAt` is when the term the contract was first entered for ends, which decides the earliest end of an
 * ordinary cancellation. Null means the term is not known, and an ordinary cancellation then waits for a person
 * rather than ending the contract on a guess.
 */
final readonly class ContractChoice
{
    public function __construct(
        public string $key,
        public string $name,
        public Subscription $subscription,
        public ?CarbonImmutable $initialTermEndsAt = null,
    ) {}
}
