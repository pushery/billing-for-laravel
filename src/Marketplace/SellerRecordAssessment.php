<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Contracts\SuppliesSellerRecords;
use Pushery\Billing\Enums\SellerFieldBasis;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\SellerRecordField;
use Pushery\Billing\ValueObjects\SellerRecordGaps;

/**
 * What a seller's record lacks today, read from the application's record and the active profile's catalog.
 *
 * Whether the seller falls under the reporting duty is read from their own activity in the current year,
 * through the same run the filing uses. It decides the basis of the reporting fields: required of a seller
 * the duty covers, collected ahead of time from one it does not. A line of activity that nobody can classify
 * leaves the answer open, and an open answer counts as NOT reportable for a measure. Holding somebody's
 * earnings needs a basis, and "we could not tell" is not one. The reminder does not wait for it.
 */
final readonly class SellerRecordAssessment
{
    public function __construct(
        private SellerRecordCompleteness $completeness,
        private ReportingProfile $profile,
        private SellerReportingRun $reporting,
        private Repository $config,
        private ?SuppliesSellerRecords $records = null,
    ) {}

    /** Whether a record source is bound at all. Without one, nothing about any record can be known. */
    public function canAssess(): bool
    {
        return $this->records instanceof SuppliesSellerRecords;
    }

    /** Null where no record source is bound. */
    public function of(Model $seller, CarbonImmutable $now): ?SellerRecordGaps
    {
        if (! $this->records instanceof SuppliesSellerRecords) {
            return null;
        }

        $values = $this->records->valuesFor($seller);
        $isLegalEntity = $this->records->isLegalEntity($seller);
        $reportable = $this->reportable($seller, $now);

        $required = $this->completeness->missingRequired($values, $isLegalEntity, $reportable === true);

        // A reminder asks for what a reportable seller has to supply, where the profile collects that ahead
        // of time. Where it does not, it asks only for what this seller has to supply now.
        $asked = $reportable !== true && $this->collectsAhead($isLegalEntity)
            ? $this->completeness->missingRequired($values, $isLegalEntity, true)
            : $required;

        return new SellerRecordGaps($asked, $required, $reportable);
    }

    private function reportable(Model $seller, CarbonImmutable $now): ?bool
    {
        $currency = $this->config->get('billing.currency', 'EUR');
        $open = false;

        foreach ($this->reporting->linesFor($seller, is_string($currency) ? $currency : 'EUR', CountingPeriod::year($now->year)) as $line) {
            try {
                if ($line->reportable()) {
                    return true;
                }
            } catch (LogicException) {
                $open = true;
            }
        }

        return $open ? null : false;
    }

    /** Whether the profile asks a seller the duty does not cover for the reporting fields anyway. */
    private function collectsAhead(bool $isLegalEntity): bool
    {
        return array_any($this->profile->fieldsFor($isLegalEntity, false), fn (SellerRecordField $field): bool => $field->basis === SellerFieldBasis::Precautionary);
    }
}
