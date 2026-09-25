<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reference of the order's own, unique where it is set.
 *
 * A cycle's order is unique on its subscription and its period. A late fee has neither, and the dunning advance
 * can run twice for the same rung: the reference it passes (`dunning:<subscription>:<rung>`) is what keeps the
 * second run from opening a second fee. The database enforces it, because a lookup followed by an insert is a
 * race between two runs that both find nothing.
 *
 * ## Why it is nullable
 *
 * Null does not collide, so every order written before this and every order that needs no key of its own keeps
 * working as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->string('reference')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
