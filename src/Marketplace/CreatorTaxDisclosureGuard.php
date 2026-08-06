<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Contracts\TaxDisclosurePolicy;
use Pushery\Billing\Exceptions\TaxDisclosureNotPermitted;
use Pushery\Billing\ValueObjects\Money;

/**
 * The hardest lock in the settlement chain: a document may state tax only for a creator whose standing
 * permits it, and this refuses the write when it does not.
 *
 * It sits BEFORE the document is written — the same precondition layer as the posture and agreement guards —
 * because the alternatives all come too late. A renderer that rejects a finished document has already
 * written the row; a check in the UI does not protect a job or a console command. So the creation call
 * itself throws here, and no row is left behind.
 *
 * The standing is resolved at the SUPPLY date, never at the moment the document happens to be generated. A
 * status can be corrected retroactively, and a monthly settlement is written well after the supplies it
 * covers — reading "now" would let a later correction rewrite the tax on a past supply. The whitelist is a
 * profile's answer (see {@see TaxDisclosurePolicy}); this class only resolves the date and enforces it, so
 * a consumer elsewhere swaps the policy and never touches the guard.
 *
 * A document that states no tax needs no permission: a zero disclosure is allowed for every standing, so the
 * tax-free variants pass straight through.
 */
final readonly class CreatorTaxDisclosureGuard
{
    public function __construct(
        private CreatorTaxStatusResolver $resolver,
        private TaxDisclosurePolicy $policy,
    ) {}

    public function assertMayDiscloseTax(Model $creator, CarbonImmutable $supplyDate, Money $taxAmount): void
    {
        if ($taxAmount->isZero()) {
            return;
        }

        $status = $this->resolver->statusAt($creator, $supplyDate);

        if (! $this->policy->permitsTaxDisclosure($status)) {
            throw TaxDisclosureNotPermitted::forStatus($status, $taxAmount);
        }
    }
}
