<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The act of filing a produced reporting record — separate from the record, because they are separate facts.
 *
 * ## Why filing is a row of its own and not a column on the export
 *
 * `billing_reporting_exports` is immutable in every column, deliberately: a row whose whole purpose is to
 * say what was produced answers nothing if it can be edited afterwards. A `filed_at` column on it would
 * have to be writable after the fact, which is the one property that table must not have — and relaxing it
 * for a single column relaxes it for the enforcement, since the guard is per-model, not per-column.
 *
 * Producing and filing are also not the same event. A period is produced, read, compared against the
 * previous run, and only then filed — or never filed at all, because the comparison showed something. A
 * shape that folds them together cannot express "produced and deliberately not filed", which is the state
 * an operator is in for most of the days between the two.
 *
 * ## The sequence column is what makes "filed twice" impossible
 *
 * `correction_sequence` is 0 for the first filing of a period and 1, 2, 3 … for each correction. It is NOT
 * NULL, so the unique key over (year, currency, sequence) bites on every engine: a second first filing
 * collides at sequence 0, and two people racing to file correction 1 collide at 1.
 *
 * Expressing the same rule as "unique where corrects_filing_id is null" would need a partial index, which
 * neither MySQL nor SQLite has — and a plain unique over a nullable column enforces nothing at all on any
 * of the three, because all of them treat NULLs in a unique index as distinct from one another. That would
 * be a constraint that reads as a guard and permits exactly the thing it names.
 *
 * ## One export is filed at most once
 *
 * A produced record is one artifact, and filing it twice is over-reporting whichever way it is spelled —
 * as a second first filing or as a correction that carries the same bytes as the thing it corrects. The
 * unique key on `export_id` closes the first spelling in the database; the register refuses the second.
 *
 * ## Restrict, never cascade
 *
 * Both foreign keys restrict. A filing is the record of a statutory act; deleting the export it filed would
 * leave a filing pointing at nothing, and cascading would delete the evidence of the act along with the
 * artifact. An export that was filed can no longer be tidied away, which is the correct answer to the
 * question the delete is really asking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reporting_filings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('export_id')->constrained('billing_reporting_exports')->restrictOnDelete();

            // Denormalized from the export on purpose: the uniqueness rule below is about the PERIOD, and a
            // unique key cannot reach through a foreign key to find one.
            $table->unsignedSmallInteger('period_year');
            $table->string('currency', 3);

            // 0 = the first filing of this period. 1, 2, 3 … = corrections, in the order they were filed.
            $table->unsignedInteger('correction_sequence');

            // Which filing this one corrects. Null exactly when the sequence is 0 — a correction that names
            // nothing is a second first filing wearing the word "correction", and the model refuses it.
            $table->foreignId('corrects_filing_id')
                ->nullable()
                ->constrained('billing_reporting_filings')
                ->restrictOnDelete();

            $table->timestamp('filed_at');

            // Who filed it. The same discipline as an acknowledgement's `acknowledged_by`, and for the same
            // reason: of everything in this row, it is the only part that cannot be reconstructed later.
            $table->string('filed_by');

            $table->timestamps();

            $table->unique(
                ['period_year', 'currency', 'correction_sequence'],
                'billing_reporting_filings_period_sequence_unique',
            );
            $table->unique('export_id', 'billing_reporting_filings_export_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reporting_filings');
    }
};
