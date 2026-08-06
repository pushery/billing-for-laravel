<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which filing obligation has been announced, and for which due date.
 *
 * ## The key is the obligation AND the date, never the date alone
 *
 * That is the whole reason the calendar exists. The last period's return and the annual seller report fall
 * due on the SAME day — different law, different data, one date. A marker keyed on the day would let the
 * first announcement silence the second, which is precisely the failure the calendar was written to prevent:
 * somebody files "the thing due at the end of January", ticks it off, and learns months later in a letter
 * that the other one was never sent.
 *
 * ## Why a table rather than a log line
 *
 * The reminder must go out once and not again — a nightly repeat turns the channel into noise — and "has
 * this been announced" is a fact somebody has to be able to read back. A log line answers nobody's query.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_filing_reminders', function (Blueprint $table): void {
            $table->id();
            $table->string('obligation', 40);
            $table->date('due_on');
            $table->timestamp('announced_at');
            $table->timestamps();

            $table->unique(['obligation', 'due_on'], 'billing_filing_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_filing_reminders');
    }
};
