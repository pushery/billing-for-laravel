<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\ValueObjects\ErasureAxis;

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
        // What somebody bought and may still open. Operational ACCESS data, not a financial record: a grant
        // needs no name to be valid and carries no retention obligation of its own — the invoice and the
        // add-on purchase do, and this row merely points at them through source_reference. Once the person
        // is erased there is nobody left to grant anything to, so keeping it would preserve an entitlement
        // with no holder while destroying nothing that any law asks for.
        'billing_access_grants',
        // A buyer's customer reference inside one merchant account: a provider key, operational, no
        // retention obligation of its own. Any money it identified lives on the retained invoice.
        'billing_merchant_customers',
    ];

    /** Kept, but unlinked from the owner: the law requires them for years. */
    public const array RETAINED = [
        'billing_invoices',
        'billing_addon_purchases',
        // A creator's own invoice, submitted through the fallback lane and reconciled before payout. It is a
        // financial document in the same sense as any invoice — it is the basis on which a payout was
        // released — so it outlives the person named on it, unlinked rather than deleted.
        'billing_submitted_invoices',

        // Which country a sale was taxed in, and what said so. It is the evidence a return was built on and
        // is kept LONGER than the documents themselves — deleting it would leave a filed return with nothing
        // supporting the country it declared. Country codes only; nothing here identifies anybody by itself.
        'billing_place_evidence',

        // The electronic document exactly as it left. It carries the same personal data the invoice does and
        // is kept for exactly as long, so it follows the invoice: unlinked, never deleted separately. An
        // artifact purged while its invoice row survived would leave a financial record that can no longer
        // prove what it stated.
        'billing_document_artifacts',

        // A voucher somebody paid for. What is left on it is a liability the platform owes, and what expired
        // off it is income already taken — both are financial facts about money that moved, so the row
        // outlives the person named on it, unlinked rather than deleted. The OWNER axis, because a voucher
        // belongs to whoever bought it; putting it on the merchant axis would have meant a merchant erasure
        // unlinking a buyer's voucher and leaving the buyer's own erasure with nothing to find.
        'billing_vouchers',

        // What a buyer declared before a digital work was provided to them. It is the evidence that their
        // right to withdraw was extinguished lawfully -- the record that decides, later, whether a refund
        // inside the window is a claim or a courtesy. Erasing it would not remove a burden from the buyer;
        // it would remove the platform's only proof and hand every past sale back to the fourteen-day rule.
        // So it outlives the person named on it, unlinked rather than deleted, exactly as the invoice does.
        'billing_withdrawal_consents',
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
     * The MERCHANT axis. A merchant is not an owner, and this is not a formality: the owner lists above split
     * on a buyer's relationship to the platform, and a merchant stands on the other side of the same sale.
     * Their rows carry `merchant_type`/`merchant_id`, so the owner lists cannot reach them at all — an
     * erasure walking the owner axis would step straight past a merchant's data and report success.
     *
     * The classification follows the owner precedent exactly, because the conflict is the same one
     * (2026-07-21 decision): a payout statement or a commission invoice is at once the merchant's personal
     * data AND a financial record the platform must keep. So the record is RETAINED and unlinked, never
     * cascaded away — with the frozen seller snapshot on the row itself, since a document that names the
     * merchant as the seller has to keep naming them after they are gone. What is purely operational is
     * PURGED, including the access a merchant's buyers hold: leaving those would keep granting access on
     * behalf of somebody who no longer exists.
     *
     * @var list<string>
     */
    public const array MERCHANT_PURGED = [
        // A merchant's provider account is an operational key, not a financial record: it identifies them
        // to the provider and does nothing else. It goes with them, which also means an inbound event about
        // an erased merchant resolves to nobody instead of to whatever their id now points at.
        'billing_merchant_accounts',

        // The subscriptions a merchant's fans hold to THEM — the access half of the classification comment
        // above. It is purely operational: it grants a fan continued access to a creator, so once that
        // creator is erased it would keep granting access on behalf of somebody who no longer exists. The
        // financial record of what the fan actually paid lives on in billing_merchant_charges (retained), so
        // purging the access state loses no money history. Single-seller rows carry no merchant and are
        // never matched by a merchant erasure.
        'billing_subscriptions',

        // Content bought FROM this merchant. The same shape as the subscriptions above and purged for the
        // same reason: a grant is the access half, and once the creator is erased it would go on granting
        // access to the work of somebody who no longer exists. What the fan actually paid survives on the
        // retained billing_merchant_charges and on the invoice, so no money history is lost. Single-seller
        // grants carry the platform sentinel and are never matched by a merchant erasure.
        'billing_access_grants',

        // The record that this merchant was warned a tax deadline was coming. It is a fact about a message
        // that was sent, and its only purpose is to keep the message from being sent twice — so once the
        // merchant is gone there is nobody left to warn and nothing the row could still prevent.
        //
        // Purged rather than retained, which is the opposite of the standings beside it in the other list:
        // a standing is EVIDENCE that justifies how documents were taxed and outlives its subject for that
        // reason. A notification receipt justifies nothing. Keeping it would mean holding somebody's
        // identity to remember an email.
        'billing_tax_hold_warnings',
    ];

    /** @var list<string> */
    public const array MERCHANT_RETAINED = [
        // A creator's tax standing over time. It is not merely their personal data: it is the evidence that
        // justifies how the documents about them were taxed, and those documents are kept for years. Delete
        // it and the retained document survives with nothing left to explain it to whoever asks.
        'billing_creator_tax_statuses',

        // What was routed to a merchant, and what has since been taken back off it. A financial record in
        // the same sense as an invoice — it says what money moved and to whom — so it outlives the person
        // named on it, unlinked rather than deleted. It is also the only place a clawback ceiling lives:
        // erasing it would leave a later reversal with nothing to cap itself against.
        'billing_merchant_charges',

        // What the provider charged the platform over one merchant's dispute. It is the platform's OWN cost,
        // and a cost with no record shows up only as an unexplained difference at the end of a month — so it
        // outlives the merchant it arose over, unlinked rather than deleted.
        'billing_provider_fees',

        // What a merchant came to owe the platform when a clawback could not take it back. A debt is a
        // financial record in the same sense as the charge that created it, and deleting it would forgive
        // it silently — the one outcome an erasure must not quietly decide. Unlinked, never deleted.
        'billing_merchant_balances',

        // The ledger account a merchant's payables were booked against. Every booking already made points at
        // it, and those bookings are kept for years — so the row outlives the merchant, unlinked. Deleting it
        // would also free the number for the next merchant, which is the one outcome that must never happen:
        // two people's obligations would then share an account, reconciling perfectly and telling nobody.
        'billing_merchant_creditor_accounts',

        // A sale whose payout waited for the buyer. It records that money moved and when a decision was
        // reached — a financial record in the same sense as the charge behind it — so it outlives the person
        // named on it, unlinked rather than deleted. Deleting it would also erase the only evidence that a
        // buyer was given the protection they were promised.
        'billing_buyer_protection_holds',

        // A seller's declaration about where they are taxed. It is the evidence for how — or whether —
        // anything was withheld and reported about them, and those obligations outlast the relationship, so
        // the row is unlinked rather than deleted. It holds no identifying number to begin with.
        'billing_us_tax_forms',

        // The proof that the documents about a merchant actually reached them. It is evidence about a
        // person, and it is also the only answer to "when did they get it" — the question a dispute over a
        // deduction date or an objection window turns on. Deleting it would leave the retained document
        // standing with nothing left to show it was ever validly delivered.
        'billing_document_deliveries',

        // The merchant's standing agreement that the platform may self-bill them. It is the evidence that
        // the self-billed documents about them were valid invoices, and those documents are kept for years —
        // so it is retained and unlinked, never deleted, exactly like the tax standing it sits beside.
        'billing_self_billing_agreements',
    ];

    /** @var array<string, list<literal-string>> */
    public const array MERCHANT_SCRUBBED = [];

    /** @var array<string, array{parent: string, foreign_key: string}> */
    public const array MERCHANT_CASCADED = [];

    /**
     * Every table an owner's data lives in — what an export has to cover to be a complete answer.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::PURGED, ...self::RETAINED, self::SCRUBBED];
    }

    /** The buyer axis: the person who holds the subscription and is named on the invoice. */
    public static function ownerAxis(): ErasureAxis
    {
        return new ErasureAxis(
            name: 'owner',
            typeColumn: 'owner_type',
            idColumn: 'owner_id',
            erasedAtColumn: 'owner_erased_at',
            purged: self::PURGED,
            retained: self::RETAINED,
            // The delivery row survives — it is the dedup that keeps a redelivery from being processed
            // twice, and what makes a failed effect replayable. Only the payload inside it is personal.
            scrubbed: [self::SCRUBBED => ['payload']],
            cascaded: self::CASCADED,
        );
    }

    /** The merchant axis: the person on the other side of a routed sale. */
    public static function merchantAxis(): ErasureAxis
    {
        return new ErasureAxis(
            name: 'merchant',
            typeColumn: 'merchant_type',
            idColumn: 'merchant_id',
            erasedAtColumn: 'merchant_erased_at',
            purged: self::MERCHANT_PURGED,
            retained: self::MERCHANT_RETAINED,
            scrubbed: self::MERCHANT_SCRUBBED,
            cascaded: self::MERCHANT_CASCADED,
        );
    }

    /**
     * Every axis the package scopes records to. Anything that walks the records of a person — the retention
     * clock above all — iterates this rather than naming one axis, so a new axis is covered the day it is
     * added instead of the day somebody remembers it.
     *
     * @return list<ErasureAxis>
     */
    public static function axes(): array
    {
        return [self::ownerAxis(), self::merchantAxis()];
    }
}
