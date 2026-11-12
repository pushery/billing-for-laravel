<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Pushery\SQLens\Attributes\NoSqlOnDriver;

/**
 * Every moment in the package's tables becomes `timestamptz` on PostgreSQL, so that the column says which moment it
 * holds rather than leaving that to a convention.
 *
 * A `timestamp without time zone` stores a wall clock. Which moment it means was the package's convention: UTC for a
 * column a model casts with UtcDateTime, the application's time zone for a created_at or updated_at Eloquent writes.
 * Anything else that writes the table, an import or a `NOW()` in a session with another zone, writes a moment that
 * reads back as a different one. A `timestamptz` holds the moment itself.
 *
 * Each column is converted from the zone its values were written in. The ones a model casts with UtcDateTime are listed
 * below and are read as UTC; every other one is read in the application's time zone, the one Eloquent wrote it in. The
 * session runs in UTC for the change, and PostgreSQL turns a timestamp into a timestamptz without rewriting the table
 * when the session's zone is the zone it reads the values in. A column in an application's zone other than UTC changes
 * its stored values, and PostgreSQL rewrites its table while holding it locked. On a large table that takes a while,
 * so the change waits at most three seconds for its lock, as MigrationLocks bounds it, and has no bound on how long it
 * then holds it. With `app.timezone` set to UTC nothing is rewritten.
 *
 * A table's columns change in one statement, so a table is rewritten at most once, and each table in a transaction of
 * its own, so a table is locked only while it changes. A run that stops part of the way leaves the tables it reached
 * converted, and running it again converts the rest: it reads which columns are still a timestamp.
 *
 * The columns are read from the catalog, so a column an application added to one of the package's tables is converted
 * too, from the application's time zone. The tables are the package's own, named below: a table of the application
 * whose name starts with the same prefix keeps its columns as they are, in both directions.
 *
 * MySQL and SQLite stay as they are. MySQL keeps the package's moments as DATETIME since 000157, which stores the wall
 * clock as it is, and SQLite stores text.
 *
 * The rollback turns each column back into a timestamp in the zone it was converted from.
 *
 * Server-only.
 */
return new
#[NoSqlOnDriver('mysql', reason: 'timestamptz is a PostgreSQL type; MySQL keeps these columns as they are')]
#[NoSqlOnDriver('mariadb', reason: 'timestamptz is a PostgreSQL type; MariaDB keeps these columns as they are')]
#[NoSqlOnDriver('sqlite', reason: 'timestamptz is a PostgreSQL type; SQLite keeps these columns as they are')]
class extends Migration
{
    /**
     * The columns a model of the package cast with UtcDateTime when this migration was written: their values are UTC.
     *
     * Written out rather than read from the models, so that the migration does on any day what it did on this one.
     *
     * @var list<string>
     */
    private const array UTC = [
        'billing_access_grants.acquired_at',
        'billing_access_grants.conformity_update_until',
        'billing_access_grants.expires_at',
        'billing_access_grants.reinstated_at',
        'billing_access_grants.revoked_at',
        'billing_access_grants.update_window_ends_at',
        'billing_access_grants.withdrawal_window_ends_at',
        'billing_buyer_protection_holds.confirm_by',
        'billing_buyer_protection_holds.decide_by',
        'billing_buyer_protection_holds.merchant_erased_at',
        'billing_buyer_protection_holds.settled_at',
        'billing_cancellation_declarations.declared_at',
        'billing_cancellation_declarations.ends_at',
        'billing_cancellation_declarations.owner_erased_at',
        'billing_cancellation_declarations.resolved_at',
        'billing_coupon_redemptions.redeemed_at',
        'billing_coupons.expires_at',
        'billing_creator_tax_statuses.attested_until',
        'billing_creator_tax_statuses.effective_from',
        'billing_creator_tax_statuses.effective_to',
        'billing_creator_tax_statuses.expiry_reminded_at',
        'billing_creator_tax_statuses.hold_announced_at',
        'billing_creator_tax_statuses.merchant_erased_at',
        'billing_creator_tax_statuses.reattestation_due_announced_at',
        'billing_credit_ledger_entries.created_at',
        'billing_customer_declarations.declared_at',
        'billing_customer_declarations.owner_erased_at',
        'billing_customer_declarations.vat_id_checked_at',
        'billing_direct_debit_notices.announced_at',
        'billing_disputes.evidence_due_by',
        'billing_disputes.merchant_erased_at',
        'billing_disputes.opened_at',
        'billing_document_artifacts.issued_at',
        'billing_document_artifacts.owner_erased_at',
        'billing_document_deliveries.merchant_erased_at',
        'billing_document_deliveries.occurred_at',
        'billing_filing_reminders.announced_at',
        'billing_in_person_sales.paid_at',
        'billing_invoices.due_at',
        'billing_invoices.invoice_effect_revoked_at',
        'billing_invoices.issued_at',
        'billing_invoices.write_off_recovered_at',
        'billing_mandate_consents.consented_at',
        'billing_mandate_consents.owner_erased_at',
        'billing_market_access_log.recorded_at',
        'billing_merchant_accounts.capabilities_refreshed_at',
        'billing_merchant_accounts.deauthorized_at',
        'billing_merchant_accounts.status_changed_at',
        'billing_merchant_balances.in_debt_since',
        'billing_merchant_balances.merchant_erased_at',
        'billing_merchant_charges.merchant_erased_at',
        'billing_merchant_charges.settled_at',
        'billing_merchant_charges.term_canceled_at',
        'billing_merchant_charges.transfer_failed_at',
        'billing_merchant_charges.transfer_requested_at',
        'billing_merchant_charges.transfer_withheld_at',
        'billing_orders.period_end',
        'billing_orders.period_start',
        'billing_orders.processed_at',
        'billing_place_evidence.owner_erased_at',
        'billing_place_evidence.resolved_at',
        'billing_provider_fees.merchant_erased_at',
        'billing_provider_fees.occurred_at',
        'billing_refund_attempts.completed_at',
        'billing_reporting_exports.generated_at',
        'billing_reporting_filings.filed_at',
        'billing_self_billing_agreements.accepted_at',
        'billing_self_billing_agreements.merchant_erased_at',
        'billing_self_billing_agreements.revoked_at',
        'billing_seller_data_escalations.first_reminded_at',
        'billing_seller_data_escalations.incomplete_since',
        'billing_seller_data_escalations.measure_started_at',
        'billing_seller_data_escalations.merchant_erased_at',
        'billing_seller_data_escalations.resolved_at',
        'billing_seller_data_escalations.second_reminded_at',
        'billing_seller_declaration_contradictions.merchant_erased_at',
        'billing_seller_declaration_contradictions.observed_at',
        'billing_seller_declarations.declared_at',
        'billing_seller_declarations.merchant_erased_at',
        'billing_settlement_restatements.processed_at',
        'billing_settlement_restatements.queued_for',
        'billing_submitted_invoices.received_at',
        'billing_subscription_intents.claimed_at',
        'billing_subscription_intents.trial_ends_at',
        'billing_subscriptions.current_period_end',
        'billing_subscriptions.current_period_start',
        'billing_subscriptions.delinquent_since',
        'billing_subscriptions.ends_at',
        'billing_subscriptions.monthly_terms_at',
        'billing_subscriptions.paused_at',
        'billing_subscriptions.scheduled_processing_at',
        'billing_subscriptions.scheduled_swap_arranged_at',
        'billing_subscriptions.scheduled_swap_at',
        'billing_subscriptions.seat_quantity_since',
        'billing_subscriptions.started_at',
        'billing_subscriptions.terminated_at',
        'billing_subscriptions.trial_ends_at',
        'billing_tax_hold_warnings.warned_at',
        'billing_tax_id_verifications.owner_erased_at',
        'billing_tax_id_verifications.reported_at',
        'billing_tax_return_exports.generated_at',
        'billing_us_tax_forms.merchant_erased_at',
        'billing_usage_events.occurred_at',
        'billing_usage_grants.expired_at',
        'billing_usage_grants.expires_at',
        'billing_usage_grants.granted_at',
        'billing_voucher_movements.occurred_on',
        'billing_voucher_volume_notices.announced_at',
        'billing_vouchers.expired_at',
        'billing_vouchers.expires_at',
        'billing_vouchers.issued_at',
        'billing_vouchers.owner_erased_at',
        'billing_withdrawal_consents.given_at',
        'billing_withdrawal_consents.owner_erased_at',
        'billing_withdrawal_declarations.declared_at',
        'billing_withdrawal_declarations.owner_erased_at',
        'billing_withdrawal_declarations.resolved_at',
    ];

    /**
     * The package's tables when this migration was written, and the only ones it converts. A table of the application
     * whose name starts with the same prefix is not the package's: its columns keep the types the application gave them,
     * on the way up and on the way back.
     *
     * Written out rather than read from the migrations, for the same reason as the list above.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'billing_access_grants',
        'billing_addon_purchases',
        'billing_ai_spend',
        'billing_buyer_protection_holds',
        'billing_cancellation_declarations',
        'billing_cancellation_surveys',
        'billing_coupon_redemptions',
        'billing_coupons',
        'billing_creator_tax_status_anchors',
        'billing_creator_tax_statuses',
        'billing_credit_balances',
        'billing_credit_ledger_entries',
        'billing_customer_declarations',
        'billing_direct_debit_notices',
        'billing_disputes',
        'billing_document_artifacts',
        'billing_document_deliveries',
        'billing_events',
        'billing_exchange_rates',
        'billing_filing_reminders',
        'billing_in_person_sales',
        'billing_invoice_exchange_rates',
        'billing_invoices',
        'billing_mandate_consents',
        'billing_market_access_log',
        'billing_merchant_accounts',
        'billing_merchant_balances',
        'billing_merchant_charges',
        'billing_merchant_creditor_accounts',
        'billing_merchant_customers',
        'billing_number_sequences',
        'billing_order_items',
        'billing_orders',
        'billing_payment_mandate_anchors',
        'billing_payment_mandates',
        'billing_place_evidence',
        'billing_prepaid_units',
        'billing_provider_fees',
        'billing_refund_attempts',
        'billing_reporting_acknowledgements',
        'billing_reporting_exports',
        'billing_reporting_filings',
        'billing_self_billing_agreements',
        'billing_seller_data_escalations',
        'billing_seller_declaration_contradictions',
        'billing_seller_declarations',
        'billing_settlement_restatements',
        'billing_shopify_purchases',
        'billing_spend_caps',
        'billing_submitted_invoices',
        'billing_subscription_intents',
        'billing_subscription_items',
        'billing_subscriptions',
        'billing_tax_hold_warnings',
        'billing_tax_id_verifications',
        'billing_tax_return_exports',
        'billing_us_tax_forms',
        'billing_usage_counters',
        'billing_usage_events',
        'billing_usage_grants',
        'billing_usage_reservations',
        'billing_voucher_movements',
        'billing_voucher_volume_notices',
        'billing_vouchers',
        'billing_webhook_effect_runs',
        'billing_webhook_events',
        'billing_withdrawal_consents',
        'billing_withdrawal_declarations',
    ];

    /**
     * Running the tables in transactions of their own rather than in the one a migration runs in, so that no table
     * stays locked while the others change.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->convert('timestamp without time zone', 'with time zone');
    }

    public function down(): void
    {
        $this->convert('timestamp with time zone', 'without time zone');
    }

    private function convert(string $from, string $to): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $prefix = $connection->getTablePrefix();
        $grammar = $connection->getQueryGrammar();
        // The zone Eloquent wrote its timestamps in: the framework sets PHP's default zone from app.timezone.
        $applicationZone = date_default_timezone_get();

        /** @var array<string, list<string>> $changes the ALTER COLUMN clauses of each table */
        $changes = [];

        foreach ($connection->select(
            'select table_name, column_name, datetime_precision from information_schema.columns '
            .'where table_schema = current_schema() and table_name like ? and data_type = ? '
            .'order by table_name, ordinal_position',
            [$prefix.'billing\\_%', $from],
        ) as $row) {
            $column = (array) $row;
            $table = substr(is_string($column['table_name'] ?? null) ? $column['table_name'] : '', strlen($prefix));

            if (! in_array($table, self::TABLES, true)) {
                continue;
            }

            $name = is_string($column['column_name'] ?? null) ? $column['column_name'] : '';
            $precision = is_numeric($column['datetime_precision'] ?? null) ? (int) $column['datetime_precision'] : 0;
            $zone = in_array($table.'.'.$name, self::UTC, true) ? 'UTC' : $applicationZone;

            $changes[$table][] = sprintf(
                'alter column %s type timestamp(%d) %s%s',
                $grammar->wrap($name),
                $precision,
                $to,
                $zone === 'UTC' ? '' : ' using '.$grammar->wrap($name).' at time zone '.$connection->escape($zone),
            );
        }

        foreach ($changes as $table => $clauses) {
            $connection->transaction(function () use ($connection, $grammar, $table, $clauses): void {
                $connection->statement("SET LOCAL lock_timeout = '3s'");
                $connection->statement('SET LOCAL statement_timeout = 0');
                // The session in UTC, so a UTC value is read and written as it was stored. Each table is rewritten
                // once: the precision every column keeps adds a length coercion PostgreSQL cannot prove a no-op.
                $connection->statement("SET LOCAL TIME ZONE 'UTC'");
                $connection->statement('alter table '.$grammar->wrapTable($table).' '.implode(', ', $clauses));
            });
        }
    }
};
