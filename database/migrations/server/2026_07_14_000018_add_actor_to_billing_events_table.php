<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the audit ledger an ACTOR and a SOURCE. Until now a row said what happened and to whom, but not
 * who did it — the actor was smuggled as free text into the payload's reason, so the ledger could not tell
 * a customer canceling their own plan from a support agent canceling it for them. The actor morph is the
 * specific user or agent; the source is the category (customer / admin / webhook / system), always present.
 *
 * Both are nullable/defaulted so every existing row and every existing writer stays valid — old rows read
 * as source "system" with no actor. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_events', function (Blueprint $table): void {
            $table->string('source')->default('system')->after('type')->index();
            $table->nullableMorphs('actor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_events', function (Blueprint $table): void {
            // Drop the index before the column it covers — SQLite refuses to drop a column an index still
            // references (the index would dangle), and dropColumn does not remove it implicitly.
            $table->dropIndex(['source']);
            $table->dropColumn('source');
            $table->dropMorphs('actor');
        });
    }
};
