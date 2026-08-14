<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\ConsumerWithdrawalPolicy;
use Pushery\Billing\Contracts\StatesWithdrawalWindow;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\Exceptions\WithdrawalConsentMissing;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * Refuses to provide a digital work until the consumer's consent is on record.
 *
 * The gate is only live where a consumer-rights profile is configured. That is deliberate and it is NOT the
 * marketplace switch: this is consumer law, which a single seller in the same country needs just as much,
 * and which an operator may run under a different jurisdiction than their tax. Off by default so an install
 * that has not opted in is byte-for-byte unchanged — no extra step, no altered receipt.
 *
 * When it is live it is fail-closed: no consent, no provision, and never "access now, confirmation later".
 * The confirmation is the thing that extinguishes the right to a refund, so granting access before it is
 * recorded gives away exactly what the record exists to protect.
 */
final readonly class WithdrawalGate
{
    public function __construct(
        private Repository $config,
        private ConsumerWithdrawalPolicy $policy,
    ) {}

    /** Whether a consumer-rights profile is active at all. */
    public function isEnforced(): bool
    {
        return $this->config->get('billing.consumer_rights.profile') !== null;
    }

    /**
     * Refuse provision of a work whose withdrawal right is not yet safely extinguished.
     *
     * @throws WithdrawalConsentMissing
     */
    public function assertMayProvide(WithdrawalType $type, ?WithdrawalConsent $consent): void
    {
        if (! $this->isEnforced()) {
            return;
        }

        if (! $this->policy->mayProvide($type, $consent)) {
            throw new WithdrawalConsentMissing($type);
        }
    }

    /**
     * What the buyer owes for the part already provided — nothing at all when no profile is active.
     *
     * The zero is the Mode-S guarantee rather than a shortcut: without a consumer-rights profile there is
     * no withdrawal right in this installation, so there is nothing to part-bill and the whole payment is
     * the caller's to deal with. Answering the policy's figure anyway would apply one jurisdiction's
     * pro-rata rule to an installation that never opted into any.
     */
    public function valueForUse(WithdrawalType $type, Money $periodGross, int $elapsedDays, int $periodDays): Money
    {
        if (! $this->isEnforced()) {
            return new Money(0, $periodGross->currency);
        }

        return $this->policy->valueForUse($type, $periodGross, $elapsedDays, $periodDays);
    }

    /**
     * When this buyer's right runs out, or null when there is no window to state.
     *
     * ## Null carries four different situations, and none of them means "no right"
     *
     * No profile is active — Mode S, and putting a date on the row would invent an obligation nobody opted
     * into. The right already extinguished. No right ever attached. Or the profile does not state windows
     * at all, which a profile is allowed not to do because {@see StatesWithdrawalWindow} is segregated.
     *
     * What they share is that no honest date exists, and a date is exactly what somebody downstream would
     * rely on. A caller that needs to tell them apart has the type and the consent in hand.
     *
     * @param  CarbonInterface  $providedAt  the moment the work was provided — not the sale, not the payment
     */
    public function windowEndsFor(WithdrawalType $type, ?WithdrawalConsent $consent, CarbonInterface $providedAt): ?CarbonInterface
    {
        if (! $this->isEnforced()) {
            return null;
        }

        if (! $this->policy instanceof StatesWithdrawalWindow) {
            return null;
        }

        return $this->policy->windowEndsFor($type, $consent, $providedAt);
    }

    /** Whether provision may proceed, for a caller choosing rather than being refused. */
    public function mayProvide(WithdrawalType $type, ?WithdrawalConsent $consent): bool
    {
        if (! $this->isEnforced()) {
            return true;
        }

        return $this->policy->mayProvide($type, $consent);
    }
}
