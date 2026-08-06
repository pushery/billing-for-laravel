<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\CountryEvidencePolicy;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * How many agreeing sources have to name a country before a sale's place is settled.
 *
 * ONE PLACE, because it was two and the two disagreed. The policy gated the sale on
 * `billing.tax_evidence.required_signals`; the evidence record was stamped from a second key,
 * `billing.tax_oss.required_signals`, that nothing else read. Both shipped defaulting to 2, so an
 * unconfigured install was accidentally consistent and no test could go red — the divergence appeared only
 * once an operator set one of them, which is exactly the moment the record starts being worth something.
 *
 * What it cost: a sale correctly settled under a one-signal standard was stamped "two required" beside its
 * single source. The row is immutable and outlives the documents built on it, so the contradiction is not
 * correctable later — an auditor reads a record that fails the standard it claims for itself, on precisely
 * the sales where the operator was in the right.
 *
 * This is a reader rather than a method on {@see CountryEvidencePolicy}, and that
 * is deliberate: the contract is published surface an adopter implements, and adding a method to it would
 * be a fatal error in their code. A shared reader costs one class and breaks nobody.
 */
final readonly class RequiredCountrySignals
{
    public function __construct(private Repository $config) {}

    /**
     * The configured standard.
     *
     * Refused rather than defaulted when unreadable: a broken value silently becoming 1 would loosen the
     * evidence standard, which is the direction that costs a defensible position rather than a click.
     */
    public function count(): int
    {
        $value = $this->config->get('billing.tax_evidence.required_signals', 2);

        if (! is_int($value) || $value < 1 || $value > 3) {
            throw InvalidBillingConfig::forKey(
                'billing.tax_evidence.required_signals',
                'must be 1, 2 or 3; a value that cannot be read would quietly relax the evidence standard',
            );
        }

        return $value;
    }
}
