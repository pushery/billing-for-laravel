<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ReportabilityVerdict;
use Pushery\Billing\ValueObjects\SellerActivity;

/**
 * A reporting profile that also decides WHO falls under its duty.
 *
 * A marker a profile opts into rather than a method every profile must have — the same shape as
 * {@see SuppliesTaxRates}, and for the same reason: widening the contract every profile satisfies would be a
 * fatal error in a consumer's own profile class.
 *
 * With no such profile bound, nobody is reportable. That is the only safe default: a core that guessed would
 * be handing personal data to an authority under a statute it knows nothing about.
 */
interface ClassifiesReportability
{
    public function classify(SellerActivity $activity): ReportabilityVerdict;
}
