<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * On PostgreSQL, a column that references one of the host's models becomes the native uuid type when the host keys its
 * models by UUID, the type Laravel's own uuidMorphs() declares.
 *
 * A host keyed by UUID declares `$table->uuid('id')`, which PostgreSQL stores as its native uuid type, and PostgreSQL
 * has no operator that compares that type with a character column. These columns were char(36), so a join from a host
 * table to one of them, which is what whereHas(), has() and withCount() compile to, failed with "operator does not
 * exist: uuid = character". The native type also stores a key in 16 bytes rather than 37.
 *
 * It changes a column only on PostgreSQL, only when the host key type is uuid, and only while the column is char(36):
 * MySQL stores a uuid as char(36) either way, and an integer or ULID key keeps its type. Every value in such a column is
 * a UUID written from a host model's key; a value that is not makes the migration fail as a whole, and PostgreSQL
 * leaves every column as it was.
 *
 * The rollback turns each uuid column back into char(36), which holds every UUID unchanged.
 */
return new class extends Migration
{
    /**
     * Every column the shipped migrations declare through BillingSchema as a reference to one of the host's models.
     *
     * @var array<string, list<string>>
     */
    private const array COLUMNS = [
        'billing_access_grants' => ['merchant_id', 'owner_id', 'purchaser_id'],
        'billing_addon_purchases' => ['owner_id'],
        'billing_buyer_protection_holds' => ['merchant_id'],
        'billing_cancellation_surveys' => ['owner_id'],
        'billing_coupon_redemptions' => ['owner_id'],
        'billing_creator_tax_status_anchors' => ['merchant_id'],
        'billing_creator_tax_statuses' => ['merchant_id'],
        'billing_credit_balances' => ['owner_id'],
        'billing_credit_ledger_entries' => ['owner_id'],
        'billing_disputes' => ['merchant_id'],
        'billing_document_artifacts' => ['owner_id'],
        'billing_document_deliveries' => ['merchant_id'],
        'billing_events' => ['actor_id', 'subject_id'],
        'billing_invoices' => ['owner_id'],
        'billing_merchant_accounts' => ['merchant_id'],
        'billing_merchant_balances' => ['merchant_id'],
        'billing_merchant_charges' => ['merchant_id'],
        'billing_merchant_creditor_accounts' => ['merchant_id'],
        'billing_merchant_customers' => ['owner_id'],
        'billing_orders' => ['owner_id'],
        'billing_payment_mandate_anchors' => ['owner_id'],
        'billing_payment_mandates' => ['owner_id'],
        'billing_place_evidence' => ['owner_id'],
        'billing_prepaid_units' => ['owner_id'],
        'billing_provider_fees' => ['merchant_id'],
        'billing_self_billing_agreements' => ['merchant_id'],
        'billing_seller_data_escalations' => ['merchant_id'],
        'billing_submitted_invoices' => ['owner_id'],
        'billing_subscription_intents' => ['owner_id'],
        'billing_subscriptions' => ['merchant_id', 'owner_id'],
        'billing_tax_hold_warnings' => ['merchant_id'],
        'billing_tax_id_verifications' => ['owner_id'],
        'billing_us_tax_forms' => ['merchant_id'],
        'billing_usage_counters' => ['owner_id'],
        'billing_usage_events' => ['owner_id'],
        'billing_usage_reservations' => ['owner_id'],
        'billing_vouchers' => ['owner_id'],
        'billing_webhook_events' => ['owner_id'],
        'billing_withdrawal_consents' => ['owner_id'],
    ];

    public function up(): void
    {
        $this->retype(from: 'bpchar', to: 'uuid', using: '::uuid');
    }

    public function down(): void
    {
        $this->retype(from: 'uuid', to: 'char(36)', using: '::text');
    }

    private function retype(string $from, string $to, string $using): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || BillingSchema::hostKeyType() !== 'uuid') {
            return;
        }

        $grammar = Schema::getConnection()->getQueryGrammar();

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column) || Schema::getColumnType($table, $column) !== $from) {
                    continue;
                }

                DB::statement(sprintf(
                    'alter table %s alter column %s type %s using %s%s',
                    $grammar->wrapTable($table),
                    $grammar->wrap($column),
                    $to,
                    $grammar->wrap($column),
                    $using,
                ));
            }
        }
    }
};
