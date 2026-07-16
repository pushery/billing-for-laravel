<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EU One-Stop-Shop (OSS) markers, frozen on the issued invoice. Under the OSS scheme a supplier charges the
 * customer's-country VAT rate on cross-border B2C digital supplies and reports it centrally, so the frozen
 * document must record WHICH destination and WHICH rate were applied — the amounts alone cannot be re-derived
 * once rates change:
 *  - oss — whether this supply was taxed under the OSS scheme (default false; most invoices are domestic).
 *  - destination_country — ISO 3166-1 alpha-2 of the customer's country, whose rate was applied.
 *  - oss_rate — the VAT percentage applied, decimal(5,2) so e.g. 19.00 / 21.00 / 27.00 are stored exactly.
 *
 * All nullable/default-safe — not every invoice is an OSS supply. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->boolean('oss')->default(false)->after('vat_note');
            $table->char('destination_country', 2)->nullable()->after('oss');
            $table->decimal('oss_rate', 5, 2)->nullable()->after('destination_country');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['oss', 'destination_country', 'oss_rate']);
        });
    }
};
