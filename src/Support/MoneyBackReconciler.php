<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\ReportsMoneyBackOutcomes;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Events\ChargebackReversed;
use Pushery\Billing\Events\RefundFailed;
use Pushery\Billing\Models\BillingWebhookEvent;
use Pushery\Billing\Models\WebhookEffectRun;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

/**
 * Records what became of refunds and chargebacks where the provider's webhook does not say, once each.
 *
 * Each outcome goes into the delivery ledger the webhook receiver writes, under the provider's id for the refund or the
 * chargeback and what became of it (`rfd_…:failed`, `chb_…:reversed`), and its event goes through the same effect bus.
 * The ledger is what makes a daily run safe: an outcome it holds as handled is skipped, so a listener hears of a failed
 * refund once, not on every run for as long as the provider still lists it. The row keeps the ids, the status and the
 * amount, and no personal data.
 *
 * An outcome whose effects did not all finish is dispatched again by the next run, which has read it from the provider
 * anew, and the effect ledger skips every effect that did finish. `billing:webhooks:replay` re-drives what a provider
 * delivered and maps nothing for these rows.
 */
final readonly class MoneyBackReconciler
{
    public function __construct(
        private ReportsMoneyBackOutcomes $provider,
        private WebhookEventLedger $deliveries,
        private WebhookEffectRegistry $registry,
        private CustomerDirectory $customers,
        private string $driver,
    ) {}

    /**
     * Record and dispatch every outcome the provider lists that the ledger does not hold as done.
     *
     * @return list<ChargebackReversed|RefundFailed> the outcomes this run dispatched
     */
    public function reconcile(CarbonImmutable $refundsSince, CarbonImmutable $chargebacksSince): array
    {
        $dispatched = [];

        foreach ($this->provider->outcomesSince($refundsSince, $chargebacksSince) as $outcome) {
            $delivery = $this->deliveries->record($this->driver, $this->keyOf($outcome), $this->typeOf($outcome), $this->factsOf($outcome));

            if (! $this->owesWork($delivery)) {
                continue;
            }

            $this->attribute($delivery, $outcome->customerReference);
            $this->registry->dispatch($outcome, $delivery);
            $this->deliveries->markHandled($delivery);

            // Logged as well as dispatched: an application that listens for nothing still finds the customer who is
            // owed money in its log.
            if ($outcome instanceof RefundFailed) {
                Log::warning('billing: a refund will not reach the customer, the provider reports it '.$outcome->failure->value, [
                    'refund' => $outcome->refundReference,
                    'payment' => $outcome->paymentReference,
                    'amount' => $outcome->amount->toDecimal().' '.$outcome->amount->currency,
                ]);
            }

            $dispatched[] = $outcome;
        }

        return $dispatched;
    }

    /** Whether an outcome still has work to do: it was never handled, or an effect of it did not finish. */
    private function owesWork(BillingWebhookEvent $delivery): bool
    {
        if ($delivery->status !== WebhookEventState::Handled) {
            return true;
        }

        return WebhookEffectRun::model()::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', '!=', WebhookEventState::Handled->value)
            ->exists();
    }

    /** Attribute the row to the owner the customer reference names, as the receiver does for a delivery. */
    private function attribute(BillingWebhookEvent $delivery, string $customerReference): void
    {
        if ($customerReference === '') {
            return;
        }

        $owner = $this->customers->ownerForReference($customerReference);

        if ($owner instanceof Model) {
            $this->deliveries->attachOwner($delivery, $owner);
        }
    }

    /** The provider's id for the refund or the chargeback, and what became of it. */
    private function keyOf(ChargebackReversed|RefundFailed $outcome): string
    {
        return $outcome instanceof RefundFailed
            ? $outcome->refundReference.':'.$outcome->failure->value
            : $outcome->disputeReference.':reversed';
    }

    private function typeOf(ChargebackReversed|RefundFailed $outcome): string
    {
        return $outcome instanceof RefundFailed ? 'refund.'.$outcome->failure->value : 'chargeback.reversed';
    }

    /** @return array<string, mixed> */
    private function factsOf(ChargebackReversed|RefundFailed $outcome): array
    {
        $amount = ['value' => $outcome->amount->toDecimal(), 'currency' => $outcome->amount->currency];

        if ($outcome instanceof RefundFailed) {
            return ['resource' => 'refund', 'id' => $outcome->refundReference, 'payment' => $outcome->paymentReference, 'status' => $outcome->failure->value, 'amount' => $amount];
        }

        return ['resource' => 'chargeback', 'id' => $outcome->disputeReference, 'payment' => $outcome->reference, 'reversed_at' => $outcome->reversedAt?->toIso8601String(), 'amount' => $amount];
    }
}
