<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * A jurisdiction's consumer-withdrawal rules, behind two questions.
 *
 * The rules are consumer law, not tax law, and the two do not move together — an operator can run one
 * country's VAT and another's consumer regime. So this is its OWN profile, bound separately from the tax
 * profile, and off by default: a consumer who has not opted in sees no extra checkout step and no changed
 * receipt.
 *
 * The core knows only the two questions. The fourteen days, the value-for-use formula, the notice wording
 * and the extinguishment rule are the profile's data, because they are a jurisdiction's answers and differ.
 */
interface ConsumerWithdrawalPolicy
{
    /**
     * Whether a work of this kind may be provided given the consent on record.
     *
     * The gate before provision. For a work whose withdrawal right extinguishes on delivery, complete
     * consent is required; for one where it does not, provision is not what forfeits the right, so the
     * consent is not a precondition of access.
     */
    public function mayProvide(WithdrawalType $type, ?WithdrawalConsent $consent): bool;

    /**
     * The value-for-use owed when a still-running service is withdrawn.
     *
     * Zero for a work whose right extinguished on delivery — there is nothing left to pro-rate. For a
     * subscription, the part of the period already elapsed, computed as a proportion of the whole rather
     * than a second rounding, so the refund and the retained value sum back to what was paid.
     */
    public function valueForUse(WithdrawalType $type, Money $periodGross, int $elapsedDays, int $periodDays): Money;
}
