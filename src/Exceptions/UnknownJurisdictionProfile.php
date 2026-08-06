<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * `billing.tax_profile` names a profile the package does not ship and the host has not bound.
 *
 * Falling back to "no profile" would be the dangerous reading: the operator asked for a jurisdiction's
 * obligations to be enforced, and would instead get a checklist that quietly omits every one of them and
 * still reports green. A typo in a config value must not be able to produce a passed preflight.
 */
final class UnknownJurisdictionProfile extends RuntimeException
{
    /** @param  list<string>  $known */
    public static function named(string $profile, array $known): self
    {
        $shipped = $known === [] ? 'none' : implode(', ', $known);

        return new self(
            "billing.tax_profile is set to [{$profile}], which is not a jurisdiction profile this package ".
            "ships (shipped: {$shipped}). Either use a shipped profile, bind your own implementation of ".
            'Pushery\\Billing\\Contracts\\JurisdictionProfile in the container, or set billing.tax_profile '.
            'to null to run the go-live checklist without jurisdiction points.'
        );
    }
}
