<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the buyer's right to withdraw runs out, frozen onto the grant.
 *
 * ## Why it can exist now, and why it was said it could not
 *
 * The configuration stated plainly that no withdrawal window was computable because "nothing records the
 * moment a work was provided". That was true when it was written. `acquired_at` on this table has recorded
 * exactly that moment since the grant register landed — written immediately after the fail-closed
 * withdrawal gate, which IS the moment of provision.
 *
 * A reason does not age visibly. That paragraph went on excusing the same absence long after the tree had
 * removed its cause, which is how it stayed unnoticed while the surrounding work shipped.
 *
 * ## Frozen rather than derived on read
 *
 * The same rule the row already applies to `update_policy` and `conformity_update_until`: an operator who
 * changes profile tomorrow changes it for future sales, not for a right somebody already holds.
 *
 * ## Nullable, and null is not "no right"
 *
 * It covers four situations that share only one thing — no honest date exists. No profile is active; the
 * right already extinguished on delivery; no right ever attached; or the profile does not state windows.
 * A reader that needs to tell them apart has `withdrawal_type` and the declaration reference on this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->timestamp('withdrawal_window_ends_at')->nullable()->after('withdrawal_declaration_ref');
        });
    }

    public function down(): void
    {
        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->dropColumn('withdrawal_window_ends_at');
        });
    }
};
