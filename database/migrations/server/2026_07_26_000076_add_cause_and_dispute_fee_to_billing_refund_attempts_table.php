<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why money is going back, and what the provider charged to handle the argument about it.
 *
 * Both are facts about the attempt, and both are recorded on it for the same reason the amounts already
 * are: they are decided when the reversal is decided, and a reading that recomputed them later would
 * answer with today's circumstances rather than the ones the reversal was made under.
 *
 * ## Why the cause cannot be inferred
 *
 * A refund and a lost dispute move money the same direction and are not the same event. The first is a
 * commercial decision that nets to zero across the three parties; the second is imposed, and the platform
 * is out of pocket by the provider's fee no matter how the shares are arranged. Anything downstream —
 * a consumer's own ledger, a merchant's statement, a dispute-rate metric — needs to tell them apart, and
 * once the attempt row is written there is nothing left in the process that still knows which it was.
 *
 * ## Why the fee is nullable rather than defaulted to zero
 *
 * Null is "no dispute happened". Zero is "a dispute happened and the provider charged nothing for it".
 * Those are different claims and a reader acts on them differently — the second is worth questioning
 * against a provider statement, the first is not. A default of zero would erase the distinction on every
 * ordinary refund and make the unusual case unfindable.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_refund_attempts', function (Blueprint $table): void {
            // Nullable, because every row written before this migration was a reversal whose cause nobody
            // recorded. Backfilling them to 'refund' would be a guess presented as a fact, and the rows
            // where it guessed wrong are precisely the disputes somebody would later go looking for.
            $table->string('cause')->nullable()->after('currency');

            $table->unsignedBigInteger('dispute_fee_minor')->nullable()->after('fee_refund_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_refund_attempts', function (Blueprint $table): void {
            $table->dropColumn(['cause', 'dispute_fee_minor']);
        });
    }
};
