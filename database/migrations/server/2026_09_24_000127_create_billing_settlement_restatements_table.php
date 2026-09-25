<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One settlement that has to be issued again because the creator's standing was corrected.
 *
 * A standing recorded with a start date in the past changes what every settlement since that date should
 * have said, and the settlements already issued say something else. Each of them becomes one row here when
 * the standing changes, and `billing:settlements:restate` works the rows off: it cancels the settlement and
 * issues it again under the standing now in force, or records why it cannot.
 *
 * Unique on the original, because a settlement is canceled at most once. A second correction of the
 * standing re-opens the row instead of adding one, and a settlement already canceled is never queued.
 *
 * No owner columns, deliberately. The settlement a row points at names its creator, and the row itself
 * holds nothing about a person, so an erasure has nothing to find here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settlement_restatements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_invoice_id');
            $table->timestamp('queued_for');
            $table->string('state', 32);
            $table->string('blocked_reason', 64)->nullable();
            $table->unsignedBigInteger('cancellation_invoice_id')->nullable();
            $table->unsignedBigInteger('replacement_invoice_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('original_invoice_id', 'billing_settlement_restatements_original_unique');
            $table->index('state', 'billing_settlement_restatements_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settlement_restatements');
    }
};
