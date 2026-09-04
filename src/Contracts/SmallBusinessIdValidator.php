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
     * A NOTE FOR WHOEVER WRITES THE REAL IMPLEMENTATION: do not reject on format.
     *
     * The obvious starting point is the ordinary tax-id validator, which refuses a malformed id locally
     * before spending a network call on it. Copied here, that pattern produces a merchant who is never
     * paid out, cannot sell, and has nothing anywhere saying why — the refusal looks exactly like a
     * correctly working fail-closed gate.
     *
     * It is not a hypothetical risk, because the two numbers do not have the same shape. The exemption
     * number is the member state's OWN identifier for that business with a suffix appended, and each
     * member state decides what its own identifier looks like. There is no pattern to match: a regex
     * derived from the other register is a guess about twenty-seven national formats at once, and every
     * one it guesses wrong is a permanent, invisible hold.
     *
     * So let the register decide. A number it does not recognize comes back as a verdict from the party
     * entitled to give one — which is the whole point of asking. Anything a local check would legitimately
     * catch (an empty value, an implausible length) is cheap to answer as `Unavailable` rather than
     * `Invalid`, because "we did not ask" is the honest description of not having asked.
     */

    /**
     * Whether the register confirms this small-business registration.
     *
     * `Unavailable` is not a soft `Invalid`. It says the question was never answered — and the caller must
     * treat that as unestablished rather than as either verdict.
     */
    public function validate(?string $registrationId): VatIdValidation;
}
