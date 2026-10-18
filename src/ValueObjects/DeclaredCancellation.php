<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\CancellationKind;
use Pushery\Billing\Enums\CancellationTiming;

/**
 * A cancellation as the person entered it on the confirmation page of the cancellation button.
 *
 * The page asks for the kind of cancellation and, for an extraordinary one, its reason; who is canceling and how to
 * reach them; which contract; when it should end; and where the confirmation goes. `contract` is the contract as
 * the person chose or typed it, which is what the declaration keeps. `contractKey` is the key of a
 * {@see ContractChoice} where the person was signed in and picked one, and it is what lets the package end that
 * contract at once.
 */
final readonly class DeclaredCancellation
{
    public function __construct(
        public CancellationKind $kind,
        public string $name,
        public string $email,
        public string $contract,
        public string $confirmationEmail,
        public CancellationTiming $ends = CancellationTiming::EarliestPossible,
        public ?CarbonImmutable $endsOn = null,
        public ?string $reason = null,
        public ?string $contractKey = null,
    ) {}
}
