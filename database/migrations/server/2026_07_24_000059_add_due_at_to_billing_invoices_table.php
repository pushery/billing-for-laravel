<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a document is due.
 *
 * The booking batch reserves a field for it, and until now that field went out empty on every row because
 * the package had nowhere to read a due date from. An empty field is not neutral there: it is what turns a
 * receivable into one nobody can age, so it never appears on a list of what is overdue.
 *
 * Nullable and written by nobody in this package — a document that states no due date keeps the empty field
 * it has always had, which is what leaves an existing batch byte for byte as it was. It exists so a consumer
 * that HAS payment terms can put them where the export can find them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('due_at');
        });
    }
};
