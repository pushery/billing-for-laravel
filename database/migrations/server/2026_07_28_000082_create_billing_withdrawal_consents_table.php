<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two declarations a buyer makes before a digital work is provided, kept.
 *
 * `WithdrawalConsent` has existed as a value object, and the gate before provision has read it, since the
 * consumer-rights profile was built. Nothing ever produced one: it arrived as an argument to
 * `ContentGrants::grant()`, no record in this package carried it, and every production call therefore
 * passed null. This is the record it was missing.
 *
 * ## Two booleans, not one
 *
 * Consent to provision beginning early and acknowledgement that provision ends the right to withdraw are
 * different statements about different things. They are stored separately because they are asked
 * separately — and because whether one combined declaration suffices is a question for an adviser. Storing
 * both flags costs nothing and makes a later answer in either direction a config change rather than a
 * migration over records whose meaning has to be guessed backwards.
 *
 * ## The wording version is the point of the row
 *
 * A sale is governed by the words shown at the time. Recording that consent was given, without recording
 * WHICH notice was shown, produces a record that a later edit silently reinterprets — the same failure the
 * seller posture and the commission terms are snapshotted to avoid. The version is a plain string because
 * the notice texts are the operator's, not the package's.
 *
 * ## One per purchase, and that is enforced here
 *
 * Unique on the owner and the reference. A buyer confirming twice on a retried checkout has consented once;
 * two rows would let a later reader find the wrong one and would make "was this sale consented to?" a
 * question with two answers. After an erasure the owner columns are null and the reference alone separates
 * the rows, which is enough: a checkout reference identifies one purchase across the whole install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_withdrawal_consents', function (Blueprint $table): void {
            $table->id();
            // NULLABLE, and paired with the stamp below. This row is RETAINED through an owner erasure --
            // it is the proof the buyer's right of withdrawal was extinguished lawfully, and destroying it
            // would hand every past sale back to the fourteen-day rule rather than relieve the buyer of
            // anything. So the person is unlinked and the fact stays, exactly as the invoice does.
            $table->nullableMorphs('owner', 'billing_withdrawal_consents_owner_index');
            // The purchase this belongs to, in the same shape the grant path already keys on: the checkout
            // reference, not a payment id, because that is what a redelivered webhook repeats.
            $table->string('reference');
            $table->boolean('consented_to_immediate_provision');
            $table->boolean('acknowledged_forfeiture');
            $table->string('notice_version');
            $table->timestamp('given_at');
            $table->timestamp('owner_erased_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'reference'], 'billing_withdrawal_consents_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_withdrawal_consents');
    }
};
