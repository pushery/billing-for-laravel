<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reference to the declaration a conformity waiver rests on.
 *
 * The register already carries `conformity_waiver` as a boolean, and a boolean alone cannot be defended. A
 * waiver of conformity updates is only capable of being valid where it was agreed separately and before the
 * contract — never as a config default or an order-form checkbox — so the ONE thing that makes the flag
 * meaningful is being able to point at the agreement behind it. A `true` with nothing behind it is
 * indistinguishable from a bug that set it.
 *
 * Opaque and foreign-key free, for the same reason as `withdrawal_declaration_ref`: a retention job pruning
 * the declaration store must not quietly turn a defensible waiver into an unexplained one.
 *
 * Nullable, and null on every ordinary row — the overwhelming majority, because the default is that
 * conformity updates are owed.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->string('conformity_waiver_ref')->nullable()->after('conformity_waiver');
        });
    }

    public function down(): void
    {
        Schema::table('billing_access_grants', function (Blueprint $table): void {
            $table->dropColumn('conformity_waiver_ref');
        });
    }
};
