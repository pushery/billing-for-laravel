<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\SellerPeriodReport;

/**
 * Where a seller's own record lives — which is the consuming application, not this package.
 *
 * {@see SellerPeriodReport} says what it deliberately does not carry: a
 * seller's name, address and statutory identifiers. The package holds the field CATALOG and the
 * completeness rule; the VALUES belong to whoever owns seller master data, and a package that stored them
 * would be a second home for personal data with no way to honor an erasure request made against the first.
 *
 * A consumer binds this to reach the records from the plausibility check. Binding it is optional, and the
 * absence is treated as a finding rather than as permission to skip: a filing cannot be assembled without
 * the records, so "nobody told us where they are" is exactly the thing an operator has to be told, not a
 * reason to report that everything is in order.
 */
interface SuppliesSellerRecords
{
    /**
     * What this seller has supplied, keyed by the field names the reporting profile asks for.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(Model $seller): array;

    /**
     * A company rather than a person.
     *
     * It changes which fields exist at all, not merely whether they are required — a date of birth is not
     * an optional field for a company, it is not a field.
     */
    public function isLegalEntity(Model $seller): bool;
}
