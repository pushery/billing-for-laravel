<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * Marker for the package's provider-neutral domain events. A WebhookReceiver translates each
 * provider's raw webhook (a signed event, a bare-id ping, an HMAC batch) into one
 * of these, so registered side-effects listen on stable domain events, never on provider strings.
 */
interface BillingDomainEvent {}
