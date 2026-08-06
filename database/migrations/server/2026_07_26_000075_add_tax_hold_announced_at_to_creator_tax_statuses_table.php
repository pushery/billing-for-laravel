<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a merchant was told their attestation had lapsed.
 *
 * A merchant reaches a tax hold two ways, and only one of them writes anything. Somebody recording a
 * blocking standing is a write, and a write can be watched. An attestation EXPIRING is not: the hold begins
 * because time passed, `statusAt()` simply starts answering "unclarified", and nothing anywhere fires.
 *
 * Detection therefore has to be a scheduled sweep, and a sweep needs to know what it has already announced —
 * otherwise it re-announces every night for as long as the hold lasts, which is how a notification channel
 * becomes one nobody reads.
 *
 * Deliberately a marker BESIDE the series rather than a change to it. The intervals are the record of what
 * was declared and when; whether somebody has been told is a fact about the telling, not about the
 * declaration. Writing the second into the first would make an announcement look like a status change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->timestamp('hold_announced_at')->nullable()->after('attested_until');
        });
    }

    public function down(): void
    {
        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->dropColumn('hold_announced_at');
        });
    }
};
