<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which country a sale was taxed in, and what said so at the time.
 *
 * The signals exist only in the moment of the sale. An address can be edited, a card replaced, a connection
 * closed — none of it can be recovered afterwards, and the resolved country is what a whole return is built
 * on. So the evidence is written when the sale happens or it does not exist.
 *
 * Country codes and nothing else. No address, no card number, no connection identifier, no coordinates, no
 * provider's raw finding. What is kept is the answer each signal gave, which is all a later reader needs and
 * the least that can be kept — the raw inputs are discarded upstream and never reach this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_place_evidence', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner', 'billing_place_evidence_owner_index');
            $table->string('reference', 128);
            $table->string('declared_country', 2)->nullable();
            $table->string('payment_country', 2)->nullable();
            $table->string('ip_country', 2)->nullable();
            $table->string('resolved_country', 2);
            // Which rule produced the answer. A consumer may swap the rule; without this, a swap would
            // silently re-interpret every case decided under the old one.
            $table->string('policy_version', 32);
            $table->unsignedTinyInteger('required_signals');
            $table->timestamp('resolved_at');
            $table->timestamp('owner_erased_at')->nullable();
            $table->timestamps();

            $table->unique('reference', 'billing_place_evidence_reference_unique');
            $table->index(['resolved_country', 'resolved_at'], 'billing_place_evidence_country_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_place_evidence');
    }
};
