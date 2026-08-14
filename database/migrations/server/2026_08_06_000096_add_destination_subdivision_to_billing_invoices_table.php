<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which SUBDIVISION of the destination country a sale was made into.
 *
 * ## What it is for
 *
 * A country-level figure is the wrong grain wherever the obligation is a subdivision's. A US sales-tax
 * nexus is reached per state, so a platform watching a national total learns it crossed a threshold in one
 * state only when the total says something about all of them — which is to say, too late or not at all.
 *
 * ## Why it is a column beside the country and not a lookup
 *
 * The country is already frozen on the document, because a document has to keep saying what the supply WAS
 * after the buyer has moved. The subdivision is the same claim at a finer grain and decays the same way,
 * so it is kept on the same terms — written once, frozen, and erased with the document rather than
 * separately.
 *
 * A join to the place evidence would have been the alternative, and it is worse in the way that matters:
 * the evidence is keyed on a reference the CONSUMER chooses, so a counter built on that join would answer
 * correctly on an installation whose references happen to line up and silently answer `unknown` on one
 * whose do not.
 *
 * ## What is deliberately NOT stored
 *
 * The signals it was derived from — above all a raw IP. Those live in the place evidence under its own
 * retention, and duplicating one here would create a second copy of a personal datum with its own lifetime.
 * Three characters, because a subdivision code is `CA`, `NY`, `BY` — never a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('destination_subdivision', 3)->nullable()->after('destination_country');

            // The pair, in the order a counter reads it: every question about a subdivision is a question
            // about a subdivision OF a country, and `NY` alone is not one.
            $table->index(['destination_country', 'destination_subdivision'], 'billing_invoices_destination_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex('billing_invoices_destination_idx');
            $table->dropColumn('destination_subdivision');
        });
    }
};
