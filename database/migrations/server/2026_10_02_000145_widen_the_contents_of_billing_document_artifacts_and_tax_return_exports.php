<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kept document keeps all of its bytes on MySQL as well.
 *
 * `billing_document_artifacts.contents` holds an electronic document exactly as it was issued, and
 * `billing_tax_return_exports.contents` an export as it was filed. Both were created as `text`, which MySQL and
 * MariaDB cap at 65 535 bytes: the CII document of an invoice with some fifty lines is larger, and the row was
 * refused in strict mode and cut short without it. `longText` holds four gigabytes there. PostgreSQL and SQLite
 * store `text` without a limit, so nothing changes on them.
 *
 * The rollback leaves the wide column in place: narrowing it again would refuse or cut the documents it may now
 * hold, and those are records the law asks to be kept as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['billing_document_artifacts', 'billing_tax_return_exports'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->longText('contents')->change();
            });
        }
    }

    public function down(): void
    {
        //
    }
};
