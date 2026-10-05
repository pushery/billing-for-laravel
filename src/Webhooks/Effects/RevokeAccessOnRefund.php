<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ContentOwnership\AccessRevocations;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\BillingDomainEvent;
use RuntimeException;

/**
 * Ends access to a refunded work — if the install says a refund should end access.
 *
 * On by default and genuinely switchable, because both answers are somebody's deliberate policy. Leaving
 * access in place after a goodwill refund is common and often the point: the work has already been read, so
 * taking it back costs nothing to skip and turns a recovered customer into an angry one. Ending it is the
 * right answer where the refund was a return rather than a gesture.
 *
 * Money that went back through a lost dispute arrives here too, because a driver reports it as a refund of the
 * purchase. That is a chargeback, so `revoke_on_chargeback` decides it and the reason recorded is CHARGEBACK.
 *
 * The reason recorded is otherwise REFUND. A statutory withdrawal reaches the same row through
 * `ConsumerWithdrawal::withdraw()`, which revokes with its own reason BEFORE the refund it triggers comes
 * back here — so this effect finds a row already revoked and leaves it alone. They end in the same state and
 * are not the same event, and flattening them here would make an audit trail that cannot tell a right the
 * buyer exercised from a decision the platform made.
 *
 * That ordering is what makes the distinction obtainable at all, and for a while it was not. Until the
 * withdrawal path wrote to the register itself, this effect always got there first — and because a
 * revocation keeps its FIRST reason, the true one could not be written afterwards. The sentence above
 * described a caller that did not exist, and a later correction failed by doing nothing at all.
 */
final readonly class RevokeAccessOnRefund implements DedupesOnReference
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

        // Money that went back through a lost dispute is a chargeback, whichever driver reports it as a refund
        // of the purchase. The install's answer for a chargeback decides, and the reason says what happened,
        // so it does not depend on whether this effect or the chargeback's own one reaches the row first.
        $chargeback = $event->reason === ReversalCause::DisputeLost->value;

        if ($this->config->get($chargeback ? 'billing.content_ownership.revoke_on_chargeback' : 'billing.content_ownership.revoke_on_refund') !== true) {
            return;
        }

        $this->revocations->revokeForPayment($event->paymentReference, $chargeback ? RevokeReason::Chargeback : RevokeReason::Refund);
    }

    /**
     * Once per refunded state of the payment: the payment, its cumulative refunded total and why it came back.
     *
     * The event carries the cumulative figure, so a redelivery repeats it and a further refund raises it. Keyed on
     * the delivery instead, a provider that pings every change to a payment under one key had its second refund
     * dropped as a duplicate of the first: Mollie does, because a refund leaves the payment `paid`. The reversal,
     * the credit note and the access revocation name the state with the same expression, so the three effects on
     * this event agree about what "the same refund" means.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof AddonRefunded) {
            throw new RuntimeException('RevokeAccessOnRefund only handles AddonRefunded events.');
        }

        return sprintf('%s:refunded:%d:%s:%s', $event->paymentReference, $event->cumulativeRefunded->minorUnits, $event->cumulativeRefunded->currency, $event->reason ?? 'refund');
    }
}
