<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Consumer\WithdrawalConsentLedger;
use Pushery\Billing\Consumer\WithdrawalGate;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Contracts\IdentifiesBusinessBuyers;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\ContractKind;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Notifications\ContractConfirmationNotification;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\OwnersZone;
use Pushery\Billing\ValueObjects\ContractConfirmation;
use RuntimeException;

/**
 * Confirms a consumer's purchase of an add-on, the contract SendContractConfirmation confirms for a subscription.
 *
 * Under the same two conditions: a consumer-rights profile is active and the operator names the view with their part
 * of it. Once per purchase, keyed on the checkout reference, so the same purchase reported twice is confirmed once.
 * None for a routed sale: who the trader is depends on the marketplace's posture, and the operator's view names the
 * platform. None for a business the bound `IdentifiesBusinessBuyers` names, for the reason the subscription's
 * confirmation gives.
 */
final readonly class SendPurchaseConfirmation implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private AddonCatalog $addons,
        private WithdrawalGate $withdrawals,
        private WithdrawalConsentLedger $consents,
        private Repository $config,
        private BillingEventLog $log,
        private IdentifiesBusinessBuyers $businesses,
    ) {}

    public function __invoke(AddonPurchased $event): void
    {
        $view = $this->config->get('billing.consumer_rights.contract_confirmation_view');

        if (! is_string($view) || trim($view) === '' || ! $this->withdrawals->isEnforced() || $this->routed($event)) {
            return;
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model || $this->businesses->isBusiness($owner)) {
            return; // a customer this app does not own, or a business
        }

        Notification::send($owner, new ContractConfirmationNotification(new ContractConfirmation(
            view: trim($view),
            kind: ContractKind::Purchase,
            startedAt: OwnersZone::of($owner, CarbonImmutable::now()),
            productKey: $event->addonKey,
            productLabel: $this->addons->label($event->addonKey),
            price: $event->amount,
            consent: $event->declarationReference === null ? null : $this->consents->for($owner, $event->declarationReference),
        )));

        $this->log->record('addon.contract_confirmation_sent', $owner, [
            'purchase' => $event->reference,
            'addon' => $event->addonKey,
        ], AuditSource::Webhook);
    }

    /** Once per purchase, whichever of the provider's events reported it. */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof AddonPurchased) {
            throw new RuntimeException('SendPurchaseConfirmation only handles AddonPurchased events.');
        }

        return $event->reference.':purchase-confirmed';
    }

    /** Whether the purchase is a routed sale, looked up on both halves of the key its table is unique on. */
    private function routed(AddonPurchased $event): bool
    {
        if ($event->provider === null || $event->paymentReference === null) {
            return false;
        }

        return MerchantCharge::model()::query()
            ->where('provider', $event->provider)
            ->where('charge_reference', $event->paymentReference)
            ->exists();
    }
}
