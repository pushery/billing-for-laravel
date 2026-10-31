<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Consumer\WithdrawalConsentLedger;
use Pushery\Billing\Consumer\WithdrawalGate;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\ContractKind;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Notifications\ContractConfirmationNotification;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\OwnersZone;
use Pushery\Billing\ValueObjects\ContractConfirmation;
use Pushery\Billing\ValueObjects\MerchantScope;
use RuntimeException;

/**
 * Confirms a consumer's subscription contract as the subscription begins, before the service does.
 *
 * German law asks for a confirmation of every distance contract on a durable medium, at the latest before the
 * service begins (§ 312f Abs. 2 BGB), with the trader's identity and address, the price and the terms, and the
 * withdrawal notice with the model withdrawal form. Most of that is the operator's: the package writes the
 * subscription it knows and the buyer's declarations, and renders the operator's view for the rest.
 *
 * Only while a consumer-rights profile is active and the operator names that view
 * (`billing.consumer_rights.contract_confirmation_view`); without the view nothing goes out, as before the effect
 * existed. Only for a subscription with the platform: one held with a merchant is a contract with that merchant, and
 * the operator's view names the platform. Only for a consumer: an owner the bound `IdentifiesBusinessBuyers` names a
 * business has no consumer contract, and a withdrawal notice addressed to them would offer a right the law does not
 * give them.
 *
 * A trial is the service as much as a paid period is, so the first of `trialing` and `active` confirms. The key is
 * claimed by those two states alone, for the reason SendSubscriptionActivatedNotice gives.
 */
final readonly class SendContractConfirmation implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private TierCatalog $tiers,
        private WithdrawalGate $withdrawals,
        private WithdrawalConsentLedger $consents,
        private Repository $config,
        private BillingEventLog $log,
        private IdentifiesBusinessBuyers $businesses,
        private PlanCatalog $plans,
    ) {}

    public function __invoke(SubscriptionStateChanged $event): void
    {
        if (! $this->begins($event->state) || ($event->merchant instanceof MerchantScope && ! $event->merchant->isPlatform())) {
            return;
        }

        $view = $this->config->get('billing.consumer_rights.contract_confirmation_view');

        if (! is_string($view) || trim($view) === '' || ! $this->withdrawals->isEnforced()) {
            return;
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model || $this->businesses->isBusiness($owner)) {
            return; // a customer this app does not own, or a business
        }

        $tier = $event->tierKey;
        // The plan at the interval the subscription is billed at, so a yearly subscription is confirmed at its
        // yearly price and period.
        $plan = $tier === null ? null : $this->plans->planFor($tier, $event->interval);

        Notification::send($owner, new ContractConfirmationNotification(new ContractConfirmation(
            view: trim($view),
            kind: ContractKind::Subscription,
            startedAt: $this->moment($owner, $event->startedAt ?? $event->periodStart ?? $event->occurredAt) ?? OwnersZone::of($owner, CarbonImmutable::now()),
            productKey: $tier,
            productLabel: $tier === null ? null : $this->tiers->label($tier),
            price: $plan->amount ?? ($tier === null ? null : $this->tiers->priceDisplay($tier)),
            periodEndsAt: $this->moment($owner, $event->periodEnd),
            trialEndsAt: $this->moment($owner, $event->trialEnd),
            consent: $event->declarationReference === null ? null : $this->consents->for($owner, $event->declarationReference),
            interval: $plan?->interval,
        )));

        $this->log->record('subscription.contract_confirmation_sent', $owner, [
            'subscription' => $event->subscriptionReference,
            'tier' => $tier,
        ], AuditSource::Webhook);
    }

    /**
     * Once per subscription, claimed by the first state in which the service begins, and a key of its own for every
     * other state, which would otherwise spend the confirmation's key on an event this effect ignores.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof SubscriptionStateChanged) {
            throw new RuntimeException('SendContractConfirmation only handles SubscriptionStateChanged events.');
        }

        $subscription = $event->subscriptionReference ?? $event->customerReference;

        return $this->begins($event->state)
            ? $subscription.':contract-confirmed'
            : $subscription.':contract-state-'.$event->state->value;
    }

    /** Whether the service begins in this state: the trial, or the first paid period. */
    private function begins(SubscriptionState $state): bool
    {
        return $state === SubscriptionState::Active || $state === SubscriptionState::Trialing;
    }

    /** A provider timestamp as a moment in the owner's zone, or null where the provider sent none. */
    private function moment(Model $owner, ?int $timestamp): ?DateTimeInterface
    {
        return $timestamp === null ? null : OwnersZone::of($owner, CarbonImmutable::createFromTimestamp($timestamp));
    }
}
