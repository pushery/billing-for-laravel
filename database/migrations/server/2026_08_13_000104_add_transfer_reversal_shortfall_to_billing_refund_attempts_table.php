<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much less of the merchant's share came back than the reversal asked for.
 *
 * The attempt already records what was ASKED for (`transfer_reversal_minor`). What actually moved is the
 * provider's answer, and on a destination charge the two systematically differ: the provider reverses
 * PROPORTIONALLY, while a fee with any fixed component owes more than the proportional share on a partial
 * refund. Both figures look reasonable, the gap is small, and it never appears in one case — it only
 * accumulates.
 *
 * ## Why the difference needs a column rather than a log line
 *
 * Without it the shortfall is not merely unrecorded, it is unrecoverable: nothing downstream can tell a
 * charge that came back short from one that came back whole, so a later top-up has nothing to aim at. A
 * log line documents the loss without making it actionable, which is the worse half of both options.
 *
 * ## Why nullable, and why zero is not the same as null
 *
 * Null is "nobody compared" — every row written before this migration, and every reversal whose provider
 * reported no amount at all. Zero is "compared, and nothing was missing". A default of zero would state
 * the second where only the first is true, and the rows it misstated would be exactly the ones somebody
 * later goes looking for.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_refund_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('transfer_reversal_short_minor')->nullable()->after('transfer_reversal_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_refund_attempts', function (Blueprint $table): void {
            $table->dropColumn('transfer_reversal_short_minor');
        });
    }
};
