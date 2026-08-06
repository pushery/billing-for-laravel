<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published exchange rates, held locally so a conversion never waits on somebody else's uptime.
 *
 * The package ships no rates and never will: which rate is correct is jurisdiction knowledge, and the rules
 * contradict each other across jurisdictions. What it ships is this table and the importers that fill it,
 * so the numbers on a consumer's documents are numbers that consumer imported, from a source it can name.
 *
 * ## Why the rule is part of the key
 *
 * The same currency pair on the same day has more than one correct rate, by law. German domestic turnover
 * takes the ministry's monthly average; the EU option takes the central bank's rate at the tax point; the
 * one-stop-shop takes the central bank's rate at period end and expressly excludes monthly averages. Two of
 * those three disagree on the same turnover.
 *
 * So `basis` is in the unique key rather than beside it. Without it, importing the ministry's average would
 * collide with the central bank's daily rate for the same day and one would silently replace the other —
 * and the survivor would be a defensible-looking number under a rule nobody asked for.
 *
 * ## What `rate_date` means, and the trap behind it
 *
 * It is the date the PUBLISHER stated, never the day the row was written. Fetched on a Saturday, a central
 * bank's daily file answers HTTP 200 carrying Friday's data — no error, no 404, the real date inside the
 * document. An importer that stamped the clock would book a rate for a day the bank never published.
 *
 * For a monthly average the date is the FIRST day of the month it covers. That is a storage convention and
 * not a claim about that day: a monthly rate has no single day, and picking the first one keeps the column
 * one type and one meaning instead of two.
 *
 * Server-only, reversible, and inert until something imports into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_exchange_rates', function (Blueprint $table): void {
            $table->id();

            // The pair as the publisher expresses it. Stored in the publisher's own direction rather than
            // normalized: a rate inverted on the way in is a rate nobody can hold against the official
            // series, and rounding an inversion at eight places does not survive the round trip.
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);

            // Not `date` -- that word is reserved or awkward on more than one engine, and a column this
            // central is not worth the quoting.
            $table->date('rate_date');

            // Which rule made this the correct rate. See ExchangeRateBasis.
            $table->string('basis');

            // Scaled by 1e8 and stored as an integer, like every other figure on the money path. A float
            // here would be the one place a published figure stopped being exactly itself.
            $table->bigInteger('rate_scaled');

            // Where it came from, in the publisher's own terms -- 'ECB' , 'BMF'. It travels onto the frozen
            // rate and from there onto the document, because "which rate" is answerable and "from whom" is
            // the question an audit asks second.
            $table->string('source');

            $table->timestamps();

            // One rate per pair, per day, per rule. The rule belongs in the key for the reason in the
            // docblock: without it the ministry average and the central bank's daily rate for the same day
            // are the same row, and the second import silently overwrites the first.
            $table->unique(['from_currency', 'to_currency', 'rate_date', 'basis'], 'billing_exchange_rates_pair_date_basis_unique');

            // The lookup the reader actually performs: a pair and a rule, then the nearest date at or after
            // the one asked for. Leading with the three equality columns lets the range scan on rate_date
            // run inside them rather than after them.
            $table->index(['from_currency', 'to_currency', 'basis', 'rate_date'], 'billing_exchange_rates_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_exchange_rates');
    }
};
