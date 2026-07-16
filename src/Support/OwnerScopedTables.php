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
    ];

    /** Kept, but unlinked from the owner: the law requires them for years. */
    public const array RETAINED = [
        'billing_invoices',
        'billing_addon_purchases',
    ];

    /** The row survives; the personal data inside it does not. */
    public const string SCRUBBED = 'billing_webhook_events';

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
