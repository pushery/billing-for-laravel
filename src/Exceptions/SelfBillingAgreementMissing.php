<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A self-billed document was about to be issued for a creator with no agreement authorizing it.
 *
 * Thrown before the document is written, for every caller — a job or a console command that never saw an
 * onboarding screen must still be refused, because a document issued without the prior agreement is not an
 * invoice and cannot be healed. The only cure is to re-issue once the creator has agreed.
 */
final class SelfBillingAgreementMissing extends RuntimeException
{
    public static function forCreator(Model $creator, CarbonInterface $supplyDate): self
    {
        return new self(sprintf(
            'No active self-billing agreement authorizes a document for a [%s] creator on a supply dated %s. '
            .'A self-billed document is an invoice only if the arrangement was agreed BEFORE the supply; one '
            .'issued without it carries no input-tax effect and cannot be healed — re-issue once the creator '
            .'has agreed. Turn off billing.marketplace.self_billing.require_agreement only in a jurisdiction '
            .'that does not require the agreement.',
            $creator->getMorphClass(),
            $supplyDate->toDateString(),
        ));
    }
}
