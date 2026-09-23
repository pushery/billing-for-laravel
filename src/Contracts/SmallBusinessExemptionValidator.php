<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\VatIdValidation;

/**
 * Asks the register whether a small-business exemption holds in one member state.
 *
 * The union's register for the cross-border small-business scheme does not answer whether a number exists.
 * It answers whether the exemption that number stands for is granted in a given member state, and without the
 * member state there is no question to send. That is why this is a contract of its own beside
 * {@see SmallBusinessIdValidator} rather than a wider signature on it: an implementation you already bound
 * there keeps working, and the default binding of this one asks it, leaving the member state out.
 *
 * The member state is the one the exemption has to hold in, which is where the creator's supply is taxed. It
 * is never the member state the business is established in: the cross-border scheme covers the others, and the
 * register refuses that question rather than answering it.
 *
 * The answer is three-valued for the reason the sibling contract gives. `Unavailable` says nobody answered;
 * the caller treats it as unestablished, never as either verdict.
 */
interface SmallBusinessExemptionValidator
{
    /** Whether the register confirms this registration's exemption in the given member state. */
    public function validate(?string $registrationId, string $memberState): VatIdValidation;
}
