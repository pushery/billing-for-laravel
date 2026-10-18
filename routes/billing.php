<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Pushery\Billing\Webhooks\AccountWebhookReceiver;
use Pushery\Billing\Webhooks\MarketplaceWebhookReceiver;
use Pushery\Billing\Webhooks\WebhookReceiver;

// Routes for the Billing for Laravel package. Loaded by the service provider via
// loadRoutesFrom(). The webhook route carries no middleware group (no CSRF): the
// driver's WebhookVerifier authenticates the request by signature instead. Both
// webhook routes carry the middleware the host names in `billing.webhook_middleware`,
// a throttle per address for instance, and none of their own.
//
// Config::string(), not a (string) cast over Config::get(): the repository is typed
// `mixed`, so a cast silently accepts whatever a consumer put in their config -- an
// array would become the literal "Array" and mount a route nobody could ever hit.
// Config::string() throws instead, naming the key, at boot.

$configuredMiddleware = Config::array('billing.webhook_middleware', []);
$webhookMiddleware = array_values(array_filter($configuredMiddleware, is_string(...)));

if (count($webhookMiddleware) !== count($configuredMiddleware)) {
    throw new InvalidArgumentException('Every entry of [billing.webhook_middleware] has to name a middleware as a string.');
}

Route::post(Config::string('billing.webhook_path', 'billing/webhook'), WebhookReceiver::class)
    ->middleware($webhookMiddleware)
    ->name('billing.webhook');

// Provider events about a merchant arrive on their own endpoint, signed with their own secret. It is a
// separate route because it is a separate trust boundary: one endpoint whose verifier accepted either
// secret would let the merchant key authenticate platform events, and those move the platform's own money.
// The receiver answers 404 while the marketplace is off, so a single-seller install exposes nothing.
Route::post(Config::string('billing.marketplace.webhook.path', 'billing/webhook/marketplace'), MarketplaceWebhookReceiver::class)
    ->middleware($webhookMiddleware)
    ->name('billing.webhook.marketplace');

// The webhooks of a payment account arrive on an address that names its provider and its key, registered last so
// the two fixed addresses above are matched first. The receiver answers 404 for a pair the application's
// PaymentAccounts does not know, so the endpoint gives away no shop that exists.
Route::post(Config::string('billing.webhook_path', 'billing/webhook').'/{provider}/{account}', AccountWebhookReceiver::class)
    ->middleware($webhookMiddleware)
    ->where(['provider' => '[a-z][a-z0-9_-]{0,31}', 'account' => '[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?'])
    ->name('billing.webhook.account');
