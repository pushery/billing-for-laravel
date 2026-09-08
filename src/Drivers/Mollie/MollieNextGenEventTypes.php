<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Webhooks\WebhookEventType;

/**
 * What this package has DECIDED about each of Mollie's next-generation webhook event types.
 *
 * ## Why a decision list and not an adoption list
 *
 * Mollie's next-generation channel carries dozens of event types and not one of them is a `payment.*` —
 * payment status still arrives on the legacy channel as a bare id to fetch. So every next-generation event
 * reaching this package today produces nothing, and the mapper said so the only way it could: by warning
 * that the delivery "named a resource that is not a payment". For a type somebody deliberately chose not to
 * act on, that is a false alarm on every single delivery, and a warning that fires on the ordinary case is
 * a warning nobody reads — which costs exactly the case it was meant to carry.
 *
 * Three states, and the middle one is the one that was missing:
 *
 * | | |
 * |---|---|
 * | **acted on** | produces domain events |
 * | **decided against** | produces nothing, SILENTLY, because a person looked at it and said no |
 * | **unclassified** | produces nothing and WARNS — Mollie added a type nobody here has considered |
 *
 * The same shape the payment-status handling next door already uses, and for the same reason: silence by
 * decision and silence by oversight look identical from the outside, so only one of them may be quiet.
 *
 * ## The list is DERIVED
 *
 * `WebhookEventType::getAll()` is the SDK's own list, so a type Mollie adds shows up here as
 * *unclassified* the day the SDK ships it, rather than matching a hand-copied literal that nobody updated.
 * What is typed below is only the DECISION — which is the part a person actually made.
 */
final class MollieNextGenEventTypes
{
    /**
     * The families this package has looked at and chosen not to act on, with the reason.
     *
     * Matched on the family prefix rather than the full type, because Mollie versions these in groups: a
     * fourth `profile.*` would be the same decision as the three that exist, and having to re-decide it
     * would mean the list rots into a warning nobody can clear.
     *
     * @var array<string, string>
     */
    private const array DECIDED_AGAINST = [
        'file' => 'file storage is not billing',
        'profile' => 'a Mollie profile is account administration, not a customer subscription',
        'business-account-transfer' => 'the platform\'s own banking, not a merchant relationship',
        'connect-balance-transfer' => 'the platform\'s own banking, not a merchant relationship',
        'unmatched-credit-transfer' => 'an unattributed inbound transfer is a reconciliation subject',
        'sales-invoice' => "Mollie's own invoices to the platform, not the invoices this package issues",
        'balance-transaction' => 'a balance movement is the aggregate of things already recorded',
        'payment-link' => 'a payment link is a checkout this package does not create',

        // NOT a permanent no. The SDK ships no dispute resource and no dispute endpoint, so the only source
        // of a dispute's data is the entity Mollie may embed in the delivery — a shape nothing here can
        // verify against. And the money direction is a clawback: a `ChargebackReceived` raised from an
        // unverified payload would double-book against the ones already derived from a payment's own
        // chargeback list, at a figure nobody checked. Reopened by a captured real payload, not by an
        // afternoon's guessing.
        'dispute' => 'no dispute resource or endpoint exists in the SDK; adoption needs a captured payload',

        // Also not permanent, and blocked on something concrete: a payout event names a merchant, and this
        // driver registers no marketplace surface at all — no rails, no marketplace webhook mapper. The
        // event would describe a payout to a merchant Mollie was never asked to pay.
        'payout' => 'the Mollie driver has no marketplace surface yet, so a payout has nowhere to land',
    ];

    /** Every type the installed SDK knows, from the SDK itself. */
    public static function known(string $type): bool
    {
        return in_array($type, WebhookEventType::getAll(), true);
    }

    /** Why this package does not act on this type, or null when it has never been classified. */
    public static function decidedAgainst(string $type): ?string
    {
        $family = str_contains($type, '.') ? strstr($type, '.', true) : $type;

        return is_string($family) ? (self::DECIDED_AGAINST[$family] ?? null) : null;
    }
}
