<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\VatIdValidation;

/**
 * Checks a merchant's small-business registration against the register that issued it.
 *
 * A SEPARATE contract from the ordinary tax-registration check, and separate on purpose: two registers,
 * two different consequences. A verified business registration means the buyer accounts for the tax; a
 * verified small-business registration means there is no tax to account for at all. One class answering
 * both would blur exactly the distinction that decides which of those two happens.
 *
 * The result is three-valued for the same reason it is on the other check — but the CONSERVATIVE DIRECTION
 * IS INVERTED HERE, and copying the caller logic across is the mistake this docblock exists to prevent.
 * On the paying side, an unreachable register means "do not zero-rate, charge the tax": never charge too
 * little. On this side, an unreachable register means HOLD: a merchant treated as an ordinary business
 * while the register is down gets the wrong settlement document and a tax charge they do not owe.
 */
interface SmallBusinessIdValidator
{
    /**
     * Whether the register confirms this small-business registration.
     *
     * `Unavailable` is not a soft `Invalid`. It says the question was never answered — and the caller must
     * treat that as unestablished rather than as either verdict.
     */
    public function validate(?string $registrationId): VatIdValidation;
}
