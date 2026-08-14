<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record that one voucher-volume level, for one currency, in one year, has been announced.
 *
 * ## Why a marker at all
 *
 * `VoucherVolumeMonitor` computed the rolling figure correctly and told nobody: no event, no command, no
 * schedule entry. An operator learned that a supervisory threshold had been passed only by asking, and
 * nothing in the package said they should ask. The sweep that fixes that runs daily, so without a marker it
 * would say the same thing every morning — and a channel that repeats becomes a channel nobody reads on the
 * day it finally means something.
 *
 * ## Why the key is currency + level + YEAR
 *
 * Not per DAY: that is the daily repeat the marker exists to stop.
 *
 * Not once ever, either, which was the first shape considered. The window is rolling, so a figure can fall
 * back under the threshold and cross it again years later under a genuinely new obligation — and a marker
 * that never expires would swallow that second crossing in silence, which is the same defect one layer
 * along.
 *
 * A calendar year is the cadence a supervisory duty actually moves on. An operator who has already notified
 * gets at most one redundant reminder a year, which costs a glance; an operator who crossed again gets told.
 *
 * Level is part of the key because approaching and breached are different messages: the first says there is
 * still time, the second says there is not. Letting one silence the other would mean the only warning that
 * mattered never arrived.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_voucher_volume_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('currency', 3);
            $table->string('level', 20);
            // The calendar year of the announcement, held as its own column rather than derived from
            // announced_at: a unique index is what enforces "once", and an index cannot be built over an
            // expression portably across the engines this package is proven on.
            $table->unsignedSmallInteger('announced_for_year');
            $table->unsignedBigInteger('volume_minor');
            $table->timestamp('announced_at');
            $table->timestamps();

            $table->unique(['currency', 'level', 'announced_for_year'], 'billing_voucher_volume_notice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_voucher_volume_notices');
    }
};
