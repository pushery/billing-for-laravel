<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WORDING the buyer was actually shown, frozen beside the version that names it.
 *
 * ## Why a snapshot and not a lookup
 *
 * The law wants the two declarations confirmed on a durable medium, and the right of withdrawal ends only
 * once that confirmation exists. A receipt that links to "our withdrawal policy" confirms nothing: the
 * linked text changes, the purchase does not.
 *
 * The same objection defeats the tidier design one level up. A registry mapping version to wording is the
 * same reference with a key in front of it — it can be edited, and it can lose a row. When it does, a legal
 * proof becomes a 404, years later, at exactly the purchase somebody is arguing about.
 *
 * So the wording travels with the consent. This package already draws that line everywhere it matters:
 * `billing_invoices` freezes the buyer's gross, the rate it was taxed at and the seller's standing onto the
 * document rather than resolving them again later. The text of a declaration is the same kind of fact.
 * Duplication across rows is the price of provability, not an oversight.
 *
 * ## Why TWO columns and not one
 *
 * They are two declarations about different things, and the package refuses to treat them as one checkbox —
 * the booleans beside them are separate for that reason. One combined blob would let a receipt carry a
 * complete-looking confirmation while one half was never shown, and nothing could tell.
 *
 * ## Why nullable, permanently
 *
 * Every consent recorded before this column existed has none, and a consumer supplying a consent built by
 * hand may have none either. Null means "the wording was not captured" — which a receipt must render as
 * nothing at all rather than as an empty confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_withdrawal_consents', function (Blueprint $table): void {
            $table->text('immediate_provision_notice')->nullable()->after('notice_version');
            $table->text('forfeiture_notice')->nullable()->after('immediate_provision_notice');
        });
    }

    public function down(): void
    {
        Schema::table('billing_withdrawal_consents', function (Blueprint $table): void {
            $table->dropColumn(['immediate_provision_notice', 'forfeiture_notice']);
        });
    }
};
