<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * Sets up the Stripe account of a payment account with the account's own key: which rights the key lacks, and
 * the account's webhook endpoint.
 *
 * A restricted key names no rights of its own, so each right is asked for with a request that carries one
 * parameter Stripe does not know. Stripe answers it with a permission error where the key lacks the right, and
 * refuses the parameter where the key holds it, so a probe reads and creates nothing either way.
 */
final readonly class StripeAccountSetup
{
    /** The parameter every probe carries, which no Stripe endpoint knows. */
    private const string PROBE = 'billing_rights_probe';

    public function __construct(
        private Repository $config,
    ) {}

    /**
     * The rights the account's key lacks, of those asked for, in the order asked.
     *
     * Only a permission error counts as a missing right. Any other refusal of a probe is the parameter it
     * carries, so the key holds the right. A key Stripe does not accept, a limit or an outage is no answer
     * about rights, and its exception reaches the caller.
     *
     * @param  list<StripeAccountRight>|null  $rights  the rights to ask for; all of them by default
     * @return list<StripeAccountRight>
     */
    public function missingRights(PaymentAccount $account, ?array $rights = null): array
    {
        $stripe = $this->client($account);
        $missing = [];

        foreach ($rights ?? StripeAccountRight::cases() as $right) {
            try {
                $this->probe($stripe, $right);
            } catch (RateLimitException $rateLimited) {
                // A 429 is an InvalidRequestException as well, and it answers nothing about the right.
                throw $rateLimited;
            } catch (PermissionException) {
                $missing[] = $right;
            } catch (InvalidRequestException) {
                // Refused for the probe's parameter, which only a key holding the right gets to hear.
            }
        }

        return $missing;
    }

    /**
     * Create the account's webhook endpoint at $url, for the events the account's webhooks read, in the API
     * version the package reads them in.
     *
     * $url is where the account's webhooks arrive, `billing.webhook_path` followed by `/stripe/` and the
     * account's key, on the host the application is reached at.
     *
     * @throws InvalidArgumentException when $url is no absolute http or https address
     */
    public function createWebhookEndpoint(PaymentAccount $account, string $url): StripeWebhookEndpoint
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("A webhook endpoint needs an absolute http or https address, and `{$url}` is not one.");
        }

        $endpoint = $this->client($account)->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => StripeAccountWebhookEventMapper::events(),
            'api_version' => $this->apiVersion(),
            'description' => "Payments of the account {$account->key}",
        ]);

        return new StripeWebhookEndpoint($endpoint->id, $endpoint->secret ?? '');
    }

    /** Remove the account's webhook endpoint. An endpoint Stripe no longer has counts as removed. */
    public function removeWebhookEndpoint(PaymentAccount $account, string $endpointId): void
    {
        try {
            $this->client($account)->webhookEndpoints->delete($endpointId);
        } catch (RateLimitException $rateLimited) {
            // A 429 is an InvalidRequestException as well, and the endpoint may still be there.
            throw $rateLimited;
        } catch (InvalidRequestException $refused) {
            if ($refused->getStripeCode() !== 'resource_missing') {
                throw $refused;
            }
        }
    }

    /**
     * One right asked for, with the parameter no endpoint knows and nothing else.
     *
     * Three of them go through the client's own request(): the typed create() of payment intents, webhook
     * endpoints and payment method domains asks for the fields a real one needs, and the probe sends its
     * unknown parameter alone, so that Stripe refuses it before anything could be created.
     */
    private function probe(StripeClient $stripe, StripeAccountRight $right): StripeObject
    {
        $probe = [self::PROBE => '1'];

        return match ($right) {
            StripeAccountRight::CheckoutSessionsWrite => $stripe->checkout->sessions->create($probe),
            StripeAccountRight::PaymentIntentsWrite => $stripe->request('post', '/v1/payment_intents', $probe, []),
            StripeAccountRight::PaymentIntentsRead => $stripe->paymentIntents->all($probe),
            StripeAccountRight::PaymentMethodsRead => $stripe->paymentMethods->all($probe),
            StripeAccountRight::RefundsWrite => $stripe->refunds->create($probe),
            StripeAccountRight::WebhookEndpointsWrite => $stripe->request('post', '/v1/webhook_endpoints', $probe, []),
            StripeAccountRight::PaymentMethodConfigurationsRead => $stripe->paymentMethodConfigurations->all($probe),
            StripeAccountRight::PaymentMethodDomainsWrite => $stripe->request('post', '/v1/payment_method_domains', $probe, []),
        };
    }

    private function client(PaymentAccount $account): StripeClient
    {
        return StripeAccountClient::for($account, $this->apiVersion());
    }

    private function apiVersion(): string
    {
        $version = $this->config->get('billing.stripe.api_version');

        return is_string($version) && $version !== '' ? $version : StripeServiceProvider::STRIPE_API_VERSION;
    }
}
