<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use LogicException;

/**
 * One kind of thing a seller sold in a period, with what the reporting rule said about it — or with the
 * fact that nothing could be said.
 *
 * The verdict is NULLABLE, and that is the point of the class rather than a detail of it. A line whose
 * settlements carry no archetype has no answer available: the rule branches on what was sold, and asking it
 * anyway returns `standardized` for the same reason an empty form returns nothing — not because the sales
 * were standardized, but because the question never reached them.
 *
 * A null verdict is therefore a statement: *these figures are real and nobody has classified them.* It is
 * kept separate from `standardized` because the two lead to opposite handling, and both mistakes are
 * violations — filing a seller the statute leaves out hands an authority personal data with no basis, and
 * omitting one it covers is the offense the duty exists to prevent.
 */
final readonly class SellerReportingLine
{
    public function __construct(
        /** What was sold and how much of it, in the terms the rule reads. */
        public SellerActivity $activity,
        /** The rule's answer, or null when the documents could not say what was sold. */
        public ?ReportabilityVerdict $verdict,
    ) {}

    /** Whether the documents behind this line said what was sold. */
    public function classified(): bool
    {
        return $this->verdict instanceof ReportabilityVerdict;
    }

    /**
     * Whether this line has to be reported.
     *
     * Refuses on an unclassified line rather than answering false. False is a decision, and this line is
     * the absence of one — a caller that could read it as "no" would quietly leave out exactly the sales
     * nobody has looked at.
     */
    public function reportable(): bool
    {
        return $this->verdict?->reportable() ?? throw new LogicException(
            'This reporting line carries no classification, so whether it has to be reported is not '
            .'something the package can answer. Its settlements name no archetype — a collective '
            .'settlement covers many kinds at once, and an older one predates the classification being '
            .'recordable at all. Resolve it from your own catalog; reading the absence as "not '
            .'reportable" is how a seller the duty covers is left out of a return.'
        );
    }
}
