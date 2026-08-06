<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ContentOwnership\AccessRevocations;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Events\ChargebackReceived;

/**
 * Ends access when a dispute is decided against a payment.
 *
 * Switchable like the refund path, and yet a different situation: a chargeback is involuntary, decided by
 * somebody else, and the money is already gone. That is why the shipped answer is to end access — there is
 * no version of this where the platform chose to give the work away.
 *
 * The reason is CHARGEBACK, kept apart from REFUND on purpose. One is a decision somebody made and can be
 * asked about; the other is a decision made against them.
 *
 * This ends access and nothing else. The money-side correction a chargeback triggers is its own cascade, and
 * this must neither anticipate nor bypass it.
 */
final readonly class RevokeAccessOnChargeback
{
    public function __construct(
        private AccessRevocations $revocations,
        private Repository $config,
    ) {}

    public function __invoke(ChargebackReceived $event): void
    {
        if ($this->config->get('billing.content_ownership.enabled') !== true) {
            return;
        }

        if ($this->config->get('billing.content_ownership.revoke_on_chargeback') !== true) {
            return;
        }

        $this->revocations->revokeForPayment($event->reference, RevokeReason::Chargeback);
    }
}
