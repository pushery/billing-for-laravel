<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\PaymentAccounts;
use Pushery\Billing\Exceptions\UnsupportedDriver;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\WebhookEventLedger;
use Pushery\Billing\ValueObjects\PaymentAccount;

/**
 * The webhook endpoint of one payment account, at `billing.webhook_path/{provider}/{account}`.
 *
 * The account is looked up through `PaymentAccounts` before anything in the request is trusted. An address
 * that names no account the application knows, or a provider without account webhooks, answers 404, so the
 * endpoint tells a caller nothing about which shops exist. A known account goes through the same spine as the
 * install's endpoint, with the account's verifier and mapper: the delivery is verified against the account's
 * webhook secret, recorded under the account's provider with its key as the reference, mapped, and queued to
 * the registered effects. A redelivery to the same account is a duplicate; the same event id at another
 * account is not.
 */
final readonly class AccountWebhookReceiver
{
    public function __construct(
        private BillingManager $manager,
        private PaymentAccounts $accounts,
        private WebhookEffectRegistry $registry,
        private WebhookEventLedger $deliveries,
        private CustomerDirectory $customers,
    ) {}

    public function __invoke(Request $request, string $provider, string $account): Response
    {
        if (! $this->manager->enabled()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $found = $this->accounts->find($provider, $account);

        // The application's lookup answers by the pair it was asked for, and an account of another provider or
        // under another key is not the one the address names.
        if (! $found instanceof PaymentAccount || $found->provider !== $provider || $found->key !== $account) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        try {
            $hooks = $this->manager->accountWebhooks($found);
        } catch (UnsupportedDriver) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new WebhookReceiver(
            $this->manager,
            $hooks->verifier,
            $hooks->mapper,
            $this->registry,
            $this->deliveries,
            $this->customers,
            $found->provider,
            $found->key,
            'account',
        )($request);
    }
}
