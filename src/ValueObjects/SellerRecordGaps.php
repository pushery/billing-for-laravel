<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a seller's record lacks, split by what may follow from it.
 *
 * Two lists because the escalation has two reaches. A reminder asks for everything the profile collects,
 * because asking is free and collecting later is the expensive case. A measure may only follow from a field
 * that is required of this seller now, which is the second list and never more than the first.
 */
final readonly class SellerRecordGaps
{
    /**
     * @param  list<string>  $asked  the fields a reminder asks for
     * @param  list<string>  $required  the fields among them that are required of this seller now
     * @param  ?bool  $reportable  whether the seller falls under the reporting duty this period; null where a
     *                             line of their activity carries no classification, so nobody can say
     */
    public function __construct(
        public array $asked,
        public array $required,
        public ?bool $reportable,
    ) {}

    public function complete(): bool
    {
        return $this->asked === [];
    }

    public function missingRequired(): bool
    {
        return $this->required !== [];
    }
}
