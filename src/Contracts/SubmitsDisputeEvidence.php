<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\DisputeEvidence;

/**
 * Answers an open dispute with the host's evidence and submits it to the provider.
 *
 * A driver whose provider takes evidence through its API implements this, and only such a driver binds it. A
 * driver without that API (Mollie) binds nothing, so a host asks the container whether it is bound before it
 * offers the step.
 *
 * Submitting is final at the provider: the case is decided on what arrived, and it cannot be amended
 * afterwards. Submitting the same evidence for the same dispute again is answered by the provider as the first
 * submission, so a retried job does not answer a case twice.
 */
interface SubmitsDisputeEvidence
{
    /**
     * @param  string  $disputeReference  the provider's dispute, as `DisputeOpened` names it
     * @param  ?string  $accountReference  the connected account the dispute lives on, as `DisputeOpened` names it;
     *                                     null on the platform's own account
     */
    public function submit(string $disputeReference, DisputeEvidence $evidence, ?string $accountReference = null): void;
}
