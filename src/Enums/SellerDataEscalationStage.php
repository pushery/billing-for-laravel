<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * How far a seller who has not supplied their required data has been escalated.
 *
 * A named stage rather than a date somebody compares against: "when did we last remind them" is a
 * reconstruction, and reconstructions disagree with each other. What has to be answerable — to a regulator,
 * to the seller, to whoever picks this up next year — is what the platform DID and when.
 */
enum SellerDataEscalationStage: string
{
    /** Nothing required is outstanding. */
    case Clear = 'clear';

    case FirstReminder = 'first_reminder';

    case SecondReminder = 'second_reminder';

    /**
     * Reminders are exhausted and a measure is in force.
     *
     * Reached only where the missing data is legally required of THIS seller. Suspending an account or
     * withholding somebody's earnings is a serious step, and taking it over data nobody is entitled to
     * demand is not compliance — it is withholding a service with nothing behind it.
     */
    case MeasureActive = 'measure_active';
}
