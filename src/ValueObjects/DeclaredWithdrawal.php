<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A withdrawal as the person entered it in the withdrawal function.
 *
 * The function asks for three things: the person's name, what identifies the contract, and the electronic address
 * the confirmation goes to. `contract` is the contract as the person chose or typed it, which is what the declaration
 * keeps. `contractKey` is the key of a {@see ContractChoice} where the person was signed in and picked one, and it is
 * what lets the package carry the withdrawal out at once.
 */
final readonly class DeclaredWithdrawal
{
    public function __construct(
        public string $name,
        public string $contract,
        public string $confirmationEmail,
        public ?string $contractKey = null,
    ) {}
}
