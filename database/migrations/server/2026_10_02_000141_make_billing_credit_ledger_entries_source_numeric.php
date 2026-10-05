<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cause of a credit movement names a row of this package, keyed by an integer, on every installation.
 *
 * `billing_credit_ledger_entries.source_id` was declared with `nullableMorphs()`, which follows the framework's
 * morph key type. An application that calls `Schema::morphUsingUuids()` or `morphUsingUlids()` therefore got a
 * uuid or a character column for it, and every movement that named its cause, an order, an add-on purchase or a
 * refund attempt, wrote an integer into it: PostgreSQL refuses that, and the movement failed with it. The table
 * is created numeric now, and this turns a column created otherwise into the integer it was meant to be.
 *
 * Where the column is already an integer, which is every installation on the framework's default, it does
 * nothing. On PostgreSQL a uuid column cannot have held a cause, because none could be written, so it is
 * retyped empty; a value in it after all is refused rather than dropped. Elsewhere the values are kept and
 * read as the numbers they are. The rollback leaves the integer column in place: it is the type the column
 * always meant, and a uuid column for this cause is the defect, not a state to return to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (str_contains(strtolower(Schema::getColumnType('billing_credit_ledger_entries', 'source_id')), 'int')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            if (DB::table('billing_credit_ledger_entries')->whereNotNull('source_id')->exists()) {
                throw new RuntimeException('billing_credit_ledger_entries.source_id holds values a uuid column could only hold if something other than this package wrote them. Inspect them before the column is retyped.');
            }

            DB::statement('ALTER TABLE billing_credit_ledger_entries ALTER COLUMN source_id TYPE bigint USING NULL');

            return;
        }

        Schema::table('billing_credit_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
