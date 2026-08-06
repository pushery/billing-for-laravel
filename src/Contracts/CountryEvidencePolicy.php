<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\CountryEvidence;
use Pushery\Billing\ValueObjects\CountrySignals;

/**
 * How contradicting country signals are resolved into one answer.
 *
 * A class rather than a branch in the checkout, because the reading is a legal one and consumers advised
 * differently must be able to swap it without forking the package. Its version travels with every answer
 * it gives, so replacing it changes what happens next rather than what already happened.
 */
interface CountryEvidencePolicy
{
    public function resolve(CountrySignals $signals): CountryEvidence;

    /** A stable identifier for this reading, stored with every sale it decides. */
    public function version(): string;
}
