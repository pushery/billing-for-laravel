<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who answered which finding, for which period, and why.
 *
 * The unique key carries the PERIOD. That is not a scoping detail — it is what stops an acknowledgement
 * from becoming a permanently disabled rule: the same finding in the next period has a different key, so
 * nothing clears it and somebody answers again. A key without the period would let one operator's judgement
 * in one January silence a check forever, with the report continuing to list it as passing.
 *
 * The currency is in the key for the same reason the run is scoped by it: a period is reported in one
 * currency, and the same seller's figures in another currency are a different report rather than a
 * converted line of this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reporting_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->string('currency', 3);
            // `rule|subject` — the finding's own identity, deliberately without its message. The message
            // carries figures, and figures move between runs; an identity that included them would let a
            // mismatch of 1.19 stay acknowledged at 1.20.
            $table->string('finding_key');
            $table->string('acknowledged_by');
            $table->timestamp('acknowledged_at');
            $table->text('reason');
            $table->timestamps();

            $table->unique(
                ['period_year', 'currency', 'finding_key'],
                'billing_reporting_acknowledgements_period_finding_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reporting_acknowledgements');
    }
};
