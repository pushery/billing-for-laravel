<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\WithdrawalType;
use RuntimeException;

/**
 * A digital work was about to be provided without the consent that extinguishes the right to withdraw.
 *
 * Refused before provision, because provision is the act that would forfeit the buyer's right, and doing it
 * without their recorded agreement means every refund inside the window is owed rather than granted. For a
 * platform that is the seller of record, that is the platform's own money at stake.
 */
final class WithdrawalConsentMissing extends RuntimeException
{
    public function __construct(public readonly WithdrawalType $type)
    {
        parent::__construct(
            "A work of withdrawal type [{$type->value}] cannot be provided without the buyer's recorded ".
            'consent to immediate provision and their acknowledgement that it ends the right to withdraw. '.
            'Providing it first would make every refund inside the window a right rather than a decision — '.
            'so access is refused until the consent is on record, never granted with confirmation to follow.'
        );
    }
}
