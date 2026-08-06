<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has been warned that the tax-standing deadline is coming, and when.
 *
 * ## Why this cannot live where the other announcement marker lives
 *
 * The lapsed-attestation sweep marks `hold_announced_at` on the status record it announced. That works
 * because such a merchant HAS a record — an attestation that ran out is a row with a date in it.
 *
 * The merchants this warns have no record at all. Never declaring is exactly what puts them in the state
 * that blocks, so there is no row of theirs to mark, and creating one would be worse than saying nothing: a
 * status record is a statement about what somebody declared, and writing a placeholder would invent a
 * declaration to hold a flag.
 *
 * ## Why the fact belongs on its own
 *
 * "This merchant has been told the deadline is coming" is a fact about the TELLING, not about their tax
 * standing — the same reasoning the existing marker's docblock gives for keeping it beside the series
 * rather than in it, one step further. A merchant who later declares gets a status record; this row stays,
 * because it records that a message was sent, and that stays true.
 *
 * ## One row per merchant, and that is the point
 *
 * The warning is sent once. A nightly re-send would turn the one channel a merchant has into noise, and the
 * message that finally matters would arrive looking exactly like the sixty before it. The unique key is
 * what enforces that rather than a query the sweep has to remember to write.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_tax_hold_warnings', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_type');
            $table->unsignedBigInteger('merchant_id');
            // The deadline the merchant was warned ABOUT, not just when. An operator who moves the date
            // forward has changed what the warning said, and the old one no longer describes the new
            // deadline — so the pair is what identifies a warning, and a moved date warns again.
            $table->date('deadline');
            $table->timestamp('warned_at');
            $table->timestamps();

            $table->unique(['merchant_type', 'merchant_id', 'deadline'], 'billing_tax_hold_warning_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tax_hold_warnings');
    }
};
