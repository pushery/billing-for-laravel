<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Http\Request;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\NamesThePayment;
use Pushery\Billing\Events\AccountPaymentChanged;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

/**
 * Records where a payment of a payment account stands, as the account's webhook would, where the provider sent none.
 *
 * Mollie calls no webhook for a refund that is canceled before it is paid out, so the payment it belongs to returns to
 * a standing it reported before without anybody hearing of it. This takes the payment's id the way Mollie's ping carries
 * it and goes the way of the account's webhook address: the account's mapper keys the standing, the ledger records it
 * under the account, and the change is dispatched to the effects. A standing the ledger already holds as handled is
 * skipped, so a second run announces nothing again; one whose effects did not all finish is dispatched again, and the
 * effect ledger skips every effect that did.
 */
final readonly class AccountPaymentReconciler
{
    public function __construct(
        private BillingManager $manager,
        private WebhookEventLedger $deliveries,
        private WebhookEffectRegistry $registry,
    ) {}

    /**
     * Read the payment again and record its standing, unless the ledger already holds that standing as handled.
     *
     * @return list<AccountPaymentChanged> the changes this dispatched
     */
    public function reconcile(PaymentAccount $account, string $paymentReference): array
    {
        $mapper = $this->manager->accountWebhooks($account)->mapper;
        $request = Request::create('/', 'POST', ['id' => $paymentReference]);
        $key = $mapper instanceof DerivesDeliveryKey ? $mapper->deliveryKey($request) : null;

        // Without a key of its own a ping would be recorded under its bare id, and every later change of the payment
        // would read as a redelivery of it. A mapper that keys nothing names no payment of this account.
        if ($key === null || trim($key) === '') {
            return [];
        }

        $delivery = $this->deliveries->record($account->provider, trim($key), 'unknown', ['id' => $paymentReference], $account->key);

        if (! $this->deliveries->owesWork($delivery)) {
            return [];
        }

        if ($mapper instanceof NamesThePayment) {
            $this->deliveries->namePaymentOf($delivery, $mapper, $request);
        }

        $changes = [];

        foreach ($mapper->map($request) as $event) {
            $this->registry->dispatch($event, $delivery);

            if ($event instanceof AccountPaymentChanged) {
                $changes[] = $event;
            }
        }

        $this->deliveries->markHandled($delivery);

        return $changes;
    }
}
