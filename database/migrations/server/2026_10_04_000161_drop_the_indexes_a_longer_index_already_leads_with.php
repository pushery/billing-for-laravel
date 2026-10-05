<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the indexes whose columns another index on the same table already leads with.
 *
 * An index on (`a`, `b`) beside one on (`a`, `b`, `c`) answers no query the longer one does not: MySQL, PostgreSQL
 * and SQLite all read the leading columns of a composite index on their own. The shorter one costs a write on every
 * insert and update of the table, and its storage. Twenty of the twenty-one index the (type, id) pair of a model
 * reference on its own, most of them as `morphs()` declares it, beside a composite that leads with the same pair;
 * the last is the plain index on a delivery's document number, which the index on (`document_number`,
 * `occurred_at`) leads with. No foreign key leans on any of them.
 *
 * An index is dropped only where it exists and the index that covers it exists too, so an installation that never
 * had it, removed it, or changed the longer one migrates all the same and keeps what it still needs. Rolling back
 * recreates each under its old name, on its old columns.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    /**
     * Each redundant index under its table: the columns it was built on, and the index that leads with them.
     *
     * @var array<string, array<string, array{columns: list<string>, coveredBy: string}>>
     */
    private const array REDUNDANT = [
        'billing_access_grants' => [
            'billing_access_grants_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_access_grants_merchant_index'],
            'billing_access_grants_owner_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_access_grants_owner_content_unique'],
        ],
        'billing_creator_tax_statuses' => [
            'billing_creator_tax_statuses_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_creator_tax_statuses_interval_unique'],
        ],
        'billing_credit_balances' => [
            'billing_credit_balances_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_credit_balances_owner_type_owner_id_currency_unique'],
        ],
        'billing_credit_ledger_entries' => [
            'billing_credit_ledger_entries_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_credit_entries_owner_currency_index'],
        ],
        'billing_document_deliveries' => [
            'billing_document_deliveries_document_number_index' => ['columns' => ['document_number'], 'coveredBy' => 'billing_document_deliveries_document_number_occurred_at_index'],
        ],
        'billing_invoices' => [
            'billing_invoices_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_invoices_owner_series_charge_unique'],
        ],
        'billing_merchant_balances' => [
            'billing_merchant_balances_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_merchant_balances_unique'],
        ],
        'billing_merchant_charges' => [
            'billing_merchant_charges_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_merchant_charges_merchant_index'],
        ],
        'billing_merchant_creditor_accounts' => [
            'billing_creditor_accounts_merchant_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_creditor_accounts_merchant_unique'],
        ],
        'billing_orders' => [
            'billing_orders_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_orders_owner_type_owner_id_status_index'],
        ],
        'billing_payment_mandates' => [
            'billing_payment_mandates_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_payment_mandates_default_index'],
        ],
        'billing_prepaid_units' => [
            'billing_prepaid_units_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_prepaid_units_owner_meter_unique'],
        ],
        'billing_self_billing_agreements' => [
            'billing_self_billing_agreements_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_self_billing_agreements_merchant_index'],
        ],
        'billing_seller_data_escalations' => [
            'billing_seller_data_escalations_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_seller_data_escalations_open_index'],
        ],
        'billing_subscriptions' => [
            'billing_subscriptions_merchant_type_merchant_id_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_subscriptions_merchant_type_merchant_id_status_index'],
            'billing_subscriptions_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_subscriptions_owner_merchant_unique'],
        ],
        'billing_us_tax_forms' => [
            'billing_us_tax_forms_merchant_index' => ['columns' => ['merchant_type', 'merchant_id'], 'coveredBy' => 'billing_us_tax_forms_seller_index'],
        ],
        'billing_usage_counters' => [
            'billing_usage_counters_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_usage_counters_owner_meter_period_unique'],
        ],
        'billing_usage_events' => [
            'billing_usage_events_owner_type_owner_id_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_usage_events_owner_meter_period_index'],
        ],
        'billing_withdrawal_consents' => [
            'billing_withdrawal_consents_owner_index' => ['columns' => ['owner_type', 'owner_id'], 'coveredBy' => 'billing_withdrawal_consents_unique'],
        ],
    ];

    public function up(): void
    {
        foreach (self::REDUNDANT as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $drop = array_keys(array_filter(
                $indexes,
                static fn (array $index, string $name): bool => Schema::hasIndex($table, $name) && Schema::hasIndex($table, $index['coveredBy']),
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($drop === []) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($drop): void {
                foreach ($drop as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $restore = array_filter($indexes, static fn (array $index, string $name): bool => ! Schema::hasIndex($table, $name), ARRAY_FILTER_USE_BOTH);

            if ($restore === []) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($restore): void {
                foreach ($restore as $name => $index) {
                    $blueprint->index($index['columns'], $name);
                }
            });
        }
    }
};
