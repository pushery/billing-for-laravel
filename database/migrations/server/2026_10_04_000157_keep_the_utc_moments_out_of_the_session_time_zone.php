<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moments the package keeps in UTC become DATETIME on MySQL, so that they no longer pass through the session time
 * zone.
 *
 * A column a model casts with UtcDateTime holds a UTC wall-clock string. MySQL converts a TIMESTAMP from the session
 * time zone to UTC as it stores it, and back as it reads it. On a connection whose time zone is not UTC each such moment
 * was therefore stored off by the offset, and read so by any session in another zone. Where that zone observes daylight
 * saving time, a moment that falls into the hour skipped in spring was refused in strict mode and moved to the end of
 * that hour without it. A DATETIME stores the string as it is. On PostgreSQL and SQLite both column types are the same
 * type, so nothing changes there.
 *
 * MySQL converts each stored TIMESTAMP to the session time zone as it changes the column, the conversion it applies
 * when the value is read, so every value reads back as it did before, as long as the migration runs on the connection
 * the application writes with. On a large table the change rewrites the table.
 *
 * A created_at or updated_at column that no model casts with UtcDateTime is written by Eloquent in the application's
 * time zone and stays as it is, like those of every other table.
 *
 * The rollback leaves the DATETIME in place: narrowing it again would refuse or move a moment that falls into the hour
 * skipped in spring of the session time zone, and refuse a moment past 2038.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->dateTime('acquired_at')->change();
            $table->dateTime('revoked_at')->nullable()->change();
            $table->dateTime('withdrawal_window_ends_at')->nullable()->change();
        });

        Schema::table('billing_buyer_protection_holds', function (Blueprint $table): void {
            $table->dateTime('confirm_by')->change();
            $table->dateTime('decide_by')->change();
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('settled_at')->nullable()->change();
        });

        Schema::table('billing_coupon_redemptions', function (Blueprint $table): void {
            $table->dateTime('redeemed_at')->change();
        });

        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->dateTime('effective_from')->change();
            $table->dateTime('expiry_reminded_at')->nullable()->change();
            $table->dateTime('hold_announced_at')->nullable()->change();
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('reattestation_due_announced_at')->nullable()->change();
        });

        Schema::table('billing_credit_ledger_entries', function (Blueprint $table): void {
            $table->dateTime('created_at')->nullable()->change();
        });

        Schema::table('billing_disputes', function (Blueprint $table): void {
            $table->dateTime('evidence_due_by')->nullable()->change();
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('opened_at')->change();
        });

        Schema::table('billing_document_artifacts', function (Blueprint $table): void {
            $table->dateTime('issued_at')->change();
            $table->dateTime('owner_erased_at')->nullable()->change();
        });

        Schema::table('billing_document_deliveries', function (Blueprint $table): void {
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('occurred_at')->change();
        });

        Schema::table('billing_filing_reminders', function (Blueprint $table): void {
            $table->dateTime('announced_at')->change();
        });

        Schema::table('billing_in_person_sales', function (Blueprint $table): void {
            $table->dateTime('paid_at')->nullable()->change();
        });

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dateTime('due_at')->nullable()->change();
            $table->dateTime('invoice_effect_revoked_at')->nullable()->change();
            $table->dateTime('issued_at')->nullable()->change();
            $table->dateTime('write_off_recovered_at')->nullable()->change();
        });

        Schema::table('billing_market_access_log', function (Blueprint $table): void {
            $table->dateTime('recorded_at')->change();
        });

        Schema::table('billing_merchant_accounts', function (Blueprint $table): void {
            $table->dateTime('capabilities_refreshed_at')->nullable()->change();
            $table->dateTime('deauthorized_at')->nullable()->change();
            $table->dateTime('status_changed_at')->nullable()->change();
        });

        Schema::table('billing_merchant_balances', function (Blueprint $table): void {
            $table->dateTime('in_debt_since')->nullable()->change();
            $table->dateTime('merchant_erased_at')->nullable()->change();
        });

        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('settled_at')->nullable()->change();
            $table->dateTime('term_canceled_at')->nullable()->change();
            $table->dateTime('transfer_failed_at')->nullable()->change();
            $table->dateTime('transfer_requested_at')->nullable()->change();
            $table->dateTime('transfer_withheld_at')->nullable()->change();
        });

        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->dateTime('period_end')->nullable()->change();
            $table->dateTime('period_start')->nullable()->change();
            $table->dateTime('processed_at')->nullable()->change();
        });

        Schema::table('billing_place_evidence', function (Blueprint $table): void {
            $table->dateTime('owner_erased_at')->nullable()->change();
            $table->dateTime('resolved_at')->change();
        });

        Schema::table('billing_provider_fees', function (Blueprint $table): void {
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('occurred_at')->change();
        });

        Schema::table('billing_refund_attempts', function (Blueprint $table): void {
            $table->dateTime('completed_at')->nullable()->change();
        });

        Schema::table('billing_reporting_exports', function (Blueprint $table): void {
            $table->dateTime('generated_at')->change();
        });

        Schema::table('billing_reporting_filings', function (Blueprint $table): void {
            $table->dateTime('filed_at')->change();
        });

        Schema::table('billing_self_billing_agreements', function (Blueprint $table): void {
            $table->dateTime('accepted_at')->change();
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('revoked_at')->nullable()->change();
        });

        Schema::table('billing_seller_data_escalations', function (Blueprint $table): void {
            $table->dateTime('first_reminded_at')->nullable()->change();
            $table->dateTime('incomplete_since')->change();
            $table->dateTime('measure_started_at')->nullable()->change();
            $table->dateTime('merchant_erased_at')->nullable()->change();
            $table->dateTime('resolved_at')->nullable()->change();
            $table->dateTime('second_reminded_at')->nullable()->change();
        });

        Schema::table('billing_settlement_restatements', function (Blueprint $table): void {
            $table->dateTime('processed_at')->nullable()->change();
            $table->dateTime('queued_for')->change();
        });

        Schema::table('billing_submitted_invoices', function (Blueprint $table): void {
            $table->dateTime('received_at')->change();
        });

        Schema::table('billing_subscription_intents', function (Blueprint $table): void {
            $table->dateTime('claimed_at')->nullable()->change();
            $table->dateTime('trial_ends_at')->nullable()->change();
        });

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dateTime('current_period_end')->nullable()->change();
            $table->dateTime('current_period_start')->nullable()->change();
            $table->dateTime('delinquent_since')->nullable()->change();
            $table->dateTime('ends_at')->nullable()->change();
            $table->dateTime('scheduled_processing_at')->nullable()->change();
            $table->dateTime('scheduled_swap_at')->nullable()->change();
            $table->dateTime('seat_quantity_since')->nullable()->change();
            $table->dateTime('started_at')->nullable()->change();
            $table->dateTime('terminated_at')->nullable()->change();
            $table->dateTime('trial_ends_at')->nullable()->change();
        });

        Schema::table('billing_tax_hold_warnings', function (Blueprint $table): void {
            $table->dateTime('warned_at')->change();
        });

        Schema::table('billing_tax_id_verifications', function (Blueprint $table): void {
            $table->dateTime('owner_erased_at')->nullable()->change();
            $table->dateTime('reported_at')->change();
        });

        Schema::table('billing_tax_return_exports', function (Blueprint $table): void {
            $table->dateTime('generated_at')->change();
        });

        Schema::table('billing_us_tax_forms', function (Blueprint $table): void {
            $table->dateTime('merchant_erased_at')->nullable()->change();
        });

        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dateTime('occurred_at')->change();
        });

        Schema::table('billing_voucher_movements', function (Blueprint $table): void {
            $table->dateTime('occurred_on')->change();
        });

        Schema::table('billing_voucher_volume_notices', function (Blueprint $table): void {
            $table->dateTime('announced_at')->change();
        });

        Schema::table('billing_vouchers', function (Blueprint $table): void {
            $table->dateTime('expired_at')->nullable()->change();
            $table->dateTime('issued_at')->change();
            $table->dateTime('owner_erased_at')->nullable()->change();
        });

        Schema::table('billing_withdrawal_consents', function (Blueprint $table): void {
            $table->dateTime('given_at')->change();
            $table->dateTime('owner_erased_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
