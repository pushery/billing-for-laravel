<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax characteristics of a sale, frozen onto the document at the moment it is issued.
 *
 * Until now exactly one such fact was frozen. The rest — what kind of thing was sold, which rule decided
 * where it was taxed, which rate band applied, the rate itself, whether the sale was reportable — were read
 * back from the PRODUCT whenever anybody asked. Products change legitimately: an author adds a video to a
 * text-only work and it loses its reduced band, a creator turns a broadcast into a private session and it
 * stops being taxed where the buyer is. Neither change may reach backwards, and both did.
 *
 * The damage has no symptom. The old document still looks right; only the relationship between it and the
 * product has stopped holding. A refund months later then reverses an amount that was never declared, and
 * a correction reports into a country the original sale never touched — as a clean, balanced reversal that
 * no test and no spot check flags.
 *
 * Every column is additive and nullable, so an existing invoice and every export of it are unchanged. And
 * this is right for a single seller too: a rate that changes next year must not rewrite last year's invoice.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('tax_archetype')->nullable()->after('oss_rate');
            $table->string('place_of_supply_rule')->nullable()->after('tax_archetype');
            $table->string('tax_rate_category')->nullable()->after('place_of_supply_rule');
            // Basis points, like every other rate in the package: the money layer is integer-only, and a
            // percentage float here would be the one place a rounding error could enter a frozen document.
            $table->unsignedInteger('tax_rate_bps')->nullable()->after('tax_rate_category');
            // Whether the sale is reportable to an authority. Frozen with the rest: reportability follows
            // from what was sold, so a reclassified product would otherwise change a past year's report.
            $table->boolean('platform_reporting')->nullable()->after('tax_rate_bps');
            // Which revision of the rate table answered. Without it, a rate that later moves leaves no way
            // to tell a historically correct document from a wrong one.
            $table->string('rate_matrix_version')->nullable()->after('platform_reporting');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_archetype', 'place_of_supply_rule', 'tax_rate_category', 'tax_rate_bps',
                'platform_reporting', 'rate_matrix_version',
            ]);
        });
    }
};
