<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Authenticates a webhook that a provider sends ABOUT A MERCHANT rather than about the platform.
 *
 * It is a separate contract with a separate secret, and the separation is not cosmetic. A provider
 * delivers merchant events to their own endpoint, signed with their own key; a platform endpoint would
 * reject every one of them. The obvious shortcut — teaching the platform verifier to accept either secret
 * — is exactly what must not happen: it would let anyone holding the merchant key forge a platform event,
 * and a platform event moves the platform's own money.
 *
 * Two contracts, two secrets, two endpoints. Neither can be used to authenticate the other's traffic.
 */
interface MarketplaceWebhookVerifier extends WebhookVerifier {}
