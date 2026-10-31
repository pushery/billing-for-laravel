<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Http\Request;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Events\AccountPaymentChanged;

/**
 * The verifier and the mapper for the webhooks of one payment account.
 *
 * Built per account by the driver of its provider: the verifier checks against the account's webhook secret,
 * and a mapper that reads back from the provider does so with the account's own client.
 */
final readonly class AccountWebhooks
{
    public function __construct(
        public WebhookVerifier $verifier,
        public WebhookEventMapper $mapper,
    ) {}

    /**
     * Where a payment of the account stands now, read back from the provider by its reference, as its webhook would
     * report it: for a page the customer returns to, which wants the answer before the webhook arrives.
     *
     * The reference is the one the charge returned: a Mollie payment, or at Stripe the Checkout Session of a charge on
     * its checkout page or a payment intent. A payment that has no state to report yet, and a reference the provider
     * does not know, answer an empty list. Each call reads from the provider.
     *
     * @return list<AccountPaymentChanged>
     */
    public function read(string $paymentReference): array
    {
        $changes = [];

        foreach ($this->mapper->map(Request::create('/', 'POST', ['id' => $paymentReference])) as $event) {
            if ($event instanceof AccountPaymentChanged) {
                $changes[] = $event;
            }
        }

        return $changes;
    }
}
