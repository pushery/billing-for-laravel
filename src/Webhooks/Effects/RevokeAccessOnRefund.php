<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ContentOwnership\AccessRevocations;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Events\AddonRefunded;

/**
 * Ends access to a refunded work — if the install says a refund should end access.
 *
 * On by default and genuinely switchable, because both answers are somebody's deliberate policy. Leaving
 * access in place after a goodwill refund is common and often the point: the work has already been read, so
 * taking it back costs nothing to skip and turns a recovered customer into an angry one. Ending it is the
 * right answer where the refund was a return rather than a gesture.
 *
 * The reason recorded is REFUND. A statutory withdrawal reaches the same row through a different call with
 * its own reason: they end in the same state and are not the same event, and flattening them here would make
 * an audit trail that cannot tell a right the buyer exercised from a decision the platform made.
 */
final readonly class RevokeAccessOnRefund
{
    public function __construct(
        private AccessRevocations $revocations,
        private Repository $config,
    ) {}

    public function __invoke(AddonRefunded $event): void
    {
        if ($this->config->get('billing.content_ownership.enabled') !== true) {
            return;
        }

        if ($this->config->get('billing.content_ownership.revoke_on_refund') !== true) {
            return;
        }

        $this->revocations->revokeForPayment($event->paymentReference, RevokeReason::Refund);
    }
}
