<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the referencing side of `billing_reporting_filings.corrects_filing_id`.
 *
 * InnoDB creates an index for a foreign key by itself, so on MySQL this has always been there. PostgreSQL
 * indexes the REFERENCED side — it is a key — and leaves the REFERENCING column bare, which is the half a
 * `foreignId()->constrained()` reads as if it covered.
 *
 * What it costs on Postgres: `restrictOnDelete()` has to prove no row references the one being deleted, and
 * without the index that proof is a sequential scan of the whole filings table on every delete. So does
 * walking the correction chain, which is what an amended reporting period IS. Neither ever fails — this is
 * a plan property, so nothing turns red and no exception is thrown; the table just gets slower in exact
 * proportion to how many periods have been filed.
 *
 * A NEW migration rather than an edit to the original, which shipped in v0.14.0: editing that one would
 * index new installs and leave every existing database exactly as it is.
 *
 * `IF NOT EXISTS` is not used, and the guard is a `hasIndex()` check instead, because the index MySQL
 * created for its own foreign key already carries this column and re-creating it there would fail.
 */
return new class extends Migration
{
    private const string TABLE = 'billing_reporting_filings';

    private const string INDEX = 'billing_reporting_filings_corrects_filing_id_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->alreadyIndexed()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index('corrects_filing_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /**
     * Whether the column already leads an index — under its own name, or under the one InnoDB gave the
     * foreign key. Asked of the LIVE schema rather than of the engine name: a guard that branched on the
     * driver would be a claim about what each engine does, and this is a question the catalog can answer.
     */
    private function alreadyIndexed(): bool
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            return true;
        }

        foreach (Schema::getIndexes(self::TABLE) as $index) {
            /** @var array{columns: list<string>} $index */
            if (($index['columns'][0] ?? null) === 'corrects_filing_id') {
                return true;
            }
        }

        return false;
    }
};
