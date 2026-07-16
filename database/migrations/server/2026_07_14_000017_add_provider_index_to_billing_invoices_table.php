<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unique index on (provider, provider_id) so persisting an invoice is idempotent.
 *
 * A provider redelivers a webhook, and one invoice fires two of them (finalized, then paid). PersistInvoice
 * upserts on this pair, so all of that converges to a single stored invoice instead of duplicates. Named
 * explicitly: the generated name would exceed MySQL's 64-character identifier limit.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unique(['provider', 'provider_id'], 'billing_invoices_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropUnique('billing_invoices_provider_unique');
        });
    }
};
