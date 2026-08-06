<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\ConsumerWithdrawalPolicy;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\Exceptions\WithdrawalConsentMissing;
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

    /** Whether provision may proceed, for a caller choosing rather than being refused. */
    public function mayProvide(WithdrawalType $type, ?WithdrawalConsent $consent): bool
    {
        if (! $this->isEnforced()) {
            return true;
        }

        return $this->policy->mayProvide($type, $consent);
    }
}
