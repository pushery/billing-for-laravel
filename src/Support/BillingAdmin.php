<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;

/**
 * The support/admin console core: the three out-of-band operations a support agent performs on an
 * owner's billing — comp a tier, cancel immediately, refund a charge — each recorded on the billing
 * audit ledger, plus a reader for that ledger. It carries no UI and no authorization of its own: an
 * app wires these into its OWN admin panel behind its OWN admin gate. Every action leaves an audit
 * trail so a comp or refund is always traceable to who did it — the app passes the acting agent as $actor.
 */
final readonly class BillingAdmin
{
    public function __construct(
        private SubscriptionActions $actions,
        private BillingManager $manager,
        private BillingEventLog $log,
        private Repository $config,
        private AddonRefunds $refunds,
    ) {}

    /**
     * Comp an owner onto a tier out of band by writing the tier column directly. Use a tier listed in
     * `billing.untouchable_tiers` so the next provider webhook does not overwrite the grant.
     */
    public function comp(Model $owner, string $tierKey, ?string $reason = null, ?Model $actor = null): void
    {
        $owner->forceFill([$this->tierColumn() => $tierKey])->save();

        $this->log->record('admin.comp', $owner, ['tier' => $tierKey, 'reason' => $reason], AuditSource::Admin, $actor);
    }

    /** Cancel an owner's subscription immediately (support-initiated), recording the reason. */
    public function cancel(Model $owner, ?string $reason = null, ?Model $actor = null): void
    {
        $this->actions->cancelNow($owner);

        $this->log->record('admin.cancel', $owner, ['reason' => $reason], AuditSource::Admin, $actor);
    }

    /**
     * Refund a charge on the active driver's rails, recording the outcome. The idempotency key makes a
     * double-click or retry safe — pass a stable key per admin action; the default collapses identical
     * refunds of the same charge + amount onto the first, so a retry cannot double-refund.
     *
     * When the refunded charge was a one-time add-on, the credit it granted is clawed back in the same
     * breath (reverse + debit + audit, atomically) — so a support refund is not a double loss: the money
     * goes back AND the customer no longer keeps the credit they were refunded for. A refund of anything
     * that is not a tracked add-on (a subscription invoice) reverses nothing. The provider round-trip is
     * kept OUTSIDE any transaction; only the local reversal is transactional.
     */
    public function refund(Model $owner, string $chargeReference, Money $amount, ?string $reason = null, ?string $idempotencyKey = null, ?Model $actor = null): RefundResult
    {
        $key = $idempotencyKey ?? 'refund:'.$chargeReference.':'.$amount->minorUnits.':'.$amount->currency;

        $result = $this->manager->driver()->rails()->refund($chargeReference, $amount, $key);

        if ($result->successful) {
            $this->refunds->reverse($chargeReference, $amount, $reason, AuditSource::Admin, $actor);
        }

        $this->log->record('admin.refund', $owner, [
            'charge' => $chargeReference,
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'reason' => $reason,
            'successful' => $result->successful,
        ], AuditSource::Admin, $actor);

        return $result;
    }

    /**
     * The owner's recent billing audit trail, newest first.
     *
     * @return array<int, BillingEvent>
     */
    public function events(Model $owner, int $limit = 50): array
    {
        return BillingEvent::query()
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    private function tierColumn(): string
    {
        $column = $this->config->get('billing.tier_column', 'plan');

        return is_string($column) ? $column : 'plan';
    }
}
