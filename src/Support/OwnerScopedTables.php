<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

/**
 * Every table the package keys to an owner, and what an erasure request may do with it. The eraser and the
 * exporter both read this ONE list, so they cannot drift apart — an export that misses a table denies a
 * person their data, and an erasure that misses one keeps it.
 *
 * The split is the whole design, and getting it backwards is worse than not doing it at all:
 *
 * PURGED — operational data with no reason to outlive the person it belongs to.
 *
 * RETAINED — the financial record. An invoice must carry the buyer's name and address to be a valid invoice
 * at all (§14 UStG), and invoices must be kept for years (§147 AO, §14b UStG). A right to erasure yields to
 * a legal retention obligation (GDPR Art. 17(3)(b)), so these rows are UNLINKED from the owner and kept,
 * and the retention clock (`billing:prune`) removes them once the law stops requiring them. Cascading them
 * away with the account would destroy tax records — a compliance gap turned into a compliance disaster.
 *
 * SCRUBBED — the stored webhook deliveries. The delivery record is what makes a failed effect replayable
 * and is the package's own account of what the provider sent, so the row stays; the raw payload inside it
 * carries the customer's email, name, billing address and card last four, so that goes.
 *
 * CASCADED — a table keyed to a PARENT row rather than to the owner. It cannot go in the lists above,
 * because the eraser and exporter filter on `owner_type`/`owner_id` and those columns do not exist on it;
 * it is reached by joining through its parent instead. Both sides read this map for the same reason they
 * read the lists above: a child table covered by one and forgotten by the other is exactly the drift this
 * class exists to prevent — an export alone leaves the data behind, a delete alone denies a person their
 * copy of it.
 */
final class OwnerScopedTables
{
    /** Deleted outright when an owner is erased. */
    public const array PURGED = [
        'billing_subscriptions',
        'billing_usage_counters',
        'billing_usage_reservations',
        'billing_usage_events',
        'billing_credit_balances',
        // Prepaid units are an entitlement, not a financial record: the money that bought them lives on the
        // (retained) add-on purchase and its invoice. Once the person is gone there is nobody left to spend
        // them, so the balance goes with them.
        'billing_prepaid_units',
        // A coupon redemption records WHO used a coupon — personal data, not a financial record (any money it
        // discounted lives on the retained invoice). It goes with the person; the coupon definition itself is
        // owner-less and stays.
        'billing_coupon_redemptions',
        // A local order is the operational billing unit a due cycle is assembled into; the RETAINED financial
        // record is the invoice produced from it, not the order itself. So an order is purged with the owner,
        // the same way the subscription it billed is.
        'billing_orders',
        // A cancellation survey is churn feedback — operational analytics, never a financial record and under
        // no retention obligation. It goes with the person: nobody is entitled to keep why someone left once
        // they are gone.
        'billing_cancellation_surveys',
    ];

    /** Kept, but unlinked from the owner: the law requires them for years. */
    public const array RETAINED = [
        'billing_invoices',
        'billing_addon_purchases',
    ];

    /** The row survives; the personal data inside it does not. */
    public const string SCRUBBED = 'billing_webhook_events';

    /**
     * Child tables, reached through their parent: table => [parent table, referencing column].
     *
     * The foreign key would cascade these away on its own, and that is deliberately NOT what the eraser
     * relies on: SQLite enforces foreign keys only when `PRAGMA foreign_keys` is on, and it is off by
     * default — an erasure obligation resting on a setting the CONSUMING application controls, failing
     * silently on the one engine where nothing would look wrong. The cascade stays as defense in depth.
     *
     * @var array<string, array{parent: string, foreign_key: string}>
     */
    public const array CASCADED = [
        'billing_subscription_items' => ['parent' => 'billing_subscriptions', 'foreign_key' => 'billing_subscription_id'],
        'billing_order_items' => ['parent' => 'billing_orders', 'foreign_key' => 'order_id'],
    ];

    /**
     * Every table an owner's data lives in — what an export has to cover to be a complete answer.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::PURGED, ...self::RETAINED, self::SCRUBBED];
    }
}
