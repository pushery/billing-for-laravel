<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Webhooks\WebhookEventType;

/**
 * What this package has DECIDED about each of Mollie's next-generation webhook event types.
 *
 * ## Why a decision list and not an adoption list
 *
 * Mollie's next-generation channel carries dozens of event types. The `payment.*` family names a payment,
 * which the mapper fetches and maps the way it maps the classic ping, so it is ACTED ON whether or not the
 * installed SDK lists the type yet. Most of the rest produce nothing here. A warning that a delivery "named
 * a resource that is not a payment" is right for a type nobody has considered, and for a type somebody chose
 * not to act on it is a false alarm on every single delivery. A warning that fires on the ordinary case is a
 * warning nobody reads, which costs exactly the case it is meant to carry.
 *
 * Three states, and the middle one is what keeps that warning for the case it is meant for:
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

        // Each of these names a part of a payment, and the payment carries the change already: the classic ping
        // of its webhookUrl arrives for it, and the mapper reads refunds, chargebacks and what was captured off
        // the fetched payment. Acting on the event as well would need a second lookup to find the payment and
        // would book the same money a second time.
        'capture' => 'the payment it belongs to carries the capture, and its own ping maps it',
        'chargeback' => 'the payment it belongs to carries the chargeback, and its own ping maps it',
        'refund' => 'the payment it belongs to carries the refund, and its own ping maps it',
    ];

    /**
     * The families the mapper acts on: each names a payment as its entity, fetched and mapped like the classic ping.
     *
     * @var list<string>
     */
    private const array ACTED_ON = ['payment'];

    /** Every type the installed SDK knows, from the SDK itself. */
    public static function known(string $type): bool
    {
        return in_array($type, WebhookEventType::getAll(), true);
    }

    /** Whether the mapper acts on this type, known to the installed SDK or not. */
    public static function actedOn(string $type): bool
    {
        return in_array(self::familyOf($type), self::ACTED_ON, true);
    }

    /** Why this package does not act on this type, or null when it has never been classified. */
    public static function decidedAgainst(string $type): ?string
    {
        return self::DECIDED_AGAINST[self::familyOf($type)] ?? null;
    }

    /** The part of a type before its first dot, `payment` for `payment.paid`. */
    private static function familyOf(string $type): string
    {
        return explode('.', $type, 2)[0];
    }
}
