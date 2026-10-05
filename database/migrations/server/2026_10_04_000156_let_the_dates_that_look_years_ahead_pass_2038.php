<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dates that are set years ahead become DATETIME, which on MySQL reaches the year 9999 where a TIMESTAMP ends at
 * 2038-01-19 03:14:07 UTC.
 *
 * A voucher's expiry, a coupon's expiry, an access grant's expiry and the ends of its update and conformity windows,
 * and the end and attestation of a creator's tax status are written ahead of the moment they name, from an operator's
 * date or a configured term. A moment past the end of the TIMESTAMP range is refused by MySQL in strict mode, so the
 * row cannot be written at all, and stored as 0000-00-00 00:00:00 without strict mode. A voucher on the default term
 * of 1095 days passes that end when it is issued after 2035-01-20 03:14:07 UTC.
 *
 * MySQL converts each stored TIMESTAMP to the connection's time zone as it changes the column, the conversion it applies
 * when the value is read, so every value reads back as it did before. On PostgreSQL and SQLite both column types are the
 * same type.
 *
 * The rollback leaves the wider columns in place, as the widening of the kept documents does: narrowing them again
 * would refuse a date past 2038 in strict mode and, without it, turn the date into 0000-00-00 00:00:00.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_vouchers', function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable()->change();
        });

        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable()->change();
        });

        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable()->change();
            $table->dateTime('update_window_ends_at')->nullable()->change();
            $table->dateTime('conformity_update_until')->nullable()->change();
        });

        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->dateTime('effective_to')->nullable()->change();
            $table->dateTime('attested_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
