<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * A jurisdiction profile that can say when a buyer's right to withdraw runs out.
 *
 * ## Its own interface, for the same reason the others are
 *
 * {@see ConsumerWithdrawalPolicy} is public surface a consumer implements to bind their own country's
 * reading. A third method on it would break every such profile the day this package upgraded, with a fatal
 * error and nothing to do about it but write the method. Segregated, a profile opts in — and one that does
 * not simply states no window, which is an honest answer rather than a broken one.
 *
 * ## The length is profile data, and so is whether there is a window at all
 *
 * Fourteen days is one country's number. More importantly, WHICH sales have a window is also a reading:
 * under the German rules a work whose right extinguished on delivery has none once the declarations were
 * made, while a subscription's runs from provision regardless. The core asks the question; it does not
 * know either answer.
 */
interface StatesWithdrawalWindow
{
    /**
     * When this buyer's right runs out, or null when there is no window to state.
     *
     * Null covers three different situations on purpose, and the caller must not read it as "no right":
     * a right that already extinguished, a sale no right ever attached to, and a sale whose regime is
     * unclassified. What they share is that no honest date can be put on the row, and a date is exactly
     * the thing somebody downstream would rely on.
     *
     * @param  CarbonInterface  $providedAt  the moment the work was provided — not the sale, not the payment
     */
    public function windowEndsFor(WithdrawalType $type, ?WithdrawalConsent $consent, CarbonInterface $providedAt): ?CarbonInterface;
}
