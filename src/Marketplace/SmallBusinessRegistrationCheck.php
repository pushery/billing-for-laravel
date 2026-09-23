<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SmallBusinessExemptionValidator;
use Pushery\Billing\Contracts\SmallBusinessIdValidator;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;
use Pushery\Billing\Enums\VatIdValidation;
use Pushery\Billing\Models\CreatorTaxStatusRecord;
use Pushery\Billing\Tax\DelegatingSmallBusinessExemptionValidator;

/**
 * Records what a register said about a merchant's small-business registration, and lets only a confirmed
 * one reach the exempt standing.
 *
 * Two of the three answers land in the same place, and that is the whole design. A registration the
 * register rejected and a register that could not be reached both leave the merchant UNESTABLISHED — not
 * "probably fine", not "treat as an ordinary business". The second case is the one worth being explicit
 * about: an outage is not evidence, and a merchant carried through on an outage gets a settlement document
 * that charges them tax they do not owe, plus a declaration of a tax that never arose.
 *
 * The check expires. A registration confirmed once is not confirmed forever — registers change, and a
 * standing that rested on a two-year-old lookup rests on nothing.
 *
 * The union's register answers for one member state at a time, so a check that names the member state the
 * exemption has to hold in asks {@see SmallBusinessExemptionValidator}, and the record says which state it
 * asked about. A check without one asks {@see SmallBusinessIdValidator}, as it always has.
 */
final readonly class SmallBusinessRegistrationCheck
{
    public function __construct(
        private Repository $config,
        private SmallBusinessIdValidator $validator,
        private CreatorTaxStatusLedger $ledger,
        private ?SmallBusinessExemptionValidator $exemptions = null,
    ) {}

    /**
     * Check a registration and record the outcome as a standing.
     *
     * @param  string  $checkReference  the register's own answer id, so a document resting on this standing
     *                                  can be traced back to the lookup that established it
     * @param  string|null  $memberState  the member state the exemption has to hold in, which is where the
     *                                    supply is taxed; never the one the business is established in
     */
    public function check(
        Model $merchant,
        ?string $registrationId,
        string $checkReference,
        ?CarbonImmutable $now = null,
        ?string $memberState = null,
    ): CreatorTaxStatusRecord {
        $at = $now ?? CarbonImmutable::now();
        $outcome = $memberState === null
            ? $this->validator->validate($registrationId)
            : ($this->exemptions ?? new DelegatingSmallBusinessExemptionValidator($this->validator))
                ->validate($registrationId, $memberState);
        $asked = $memberState === null ? '' : ' for '.strtoupper(trim($memberState));

        return $this->ledger->record(
            merchant: $merchant,
            // Only a confirmation reaches the exempt standing. Both other answers mean nobody established
            // anything, which is a state with its own name and its own consequences.
            status: $outcome === VatIdValidation::Valid
                ? CreatorTaxStatus::UnionSmallBusinessExempt
                : CreatorTaxStatus::Unclarified,
            effectiveFrom: $at,
            source: CreatorTaxStatusSource::RegistryCheck,
            // The proof, not a log line: when, what was asked, what came back, and the register's own
            // reference for it.
            evidenceRef: sprintf('%s at %s%s: %s', $checkReference, $at->toIso8601String(), $asked, $outcome->value),
            // Only a confirmation gets a clock. An unestablished standing does not expire — there is
            // nothing to expire.
            attestedUntil: $outcome === VatIdValidation::Valid ? $at->addDays($this->validityDays()) : null,
        );
    }

    /** How long a confirmation stands before it has to be asked again. */
    public function validityDays(): int
    {
        $days = $this->config->get('billing.tax_small_business.eu_revalidate_after_days', 365);

        return is_int($days) && $days > 0 ? $days : 365;
    }
}
