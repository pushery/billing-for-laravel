<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Who or what performed an audited billing action — the "on whose behalf, from where" a real audit trail
 * needs. An audit log that cannot tell a customer canceling their own subscription from a support agent
 * canceling it for them, or from a provider webhook doing it, is not an audit trail.
 *
 * The actor morph answers WHO (the specific user or agent); this answers the CATEGORY, and is always
 * present even when the actor is not — a webhook effect runs in a queued job with no authenticated user, so
 * its source is Webhook and its actor is null.
 */
enum AuditSource: string
{
    case Customer = 'customer';   // the account's own user, acting on their own billing (the hub screens)
    case Admin = 'admin';         // a support agent, out of band (BillingAdmin)
    case Webhook = 'webhook';     // a provider-driven effect (no authenticated user)
    case System = 'system';       // the scheduler / console (dunning advance, prune, erasure)
}
