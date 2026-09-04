<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mandates a local-engine driver may charge later, mirrored locally.
 *
 * Under Stripe the stored methods live at the provider and are read back over the API. A local engine
 * cannot do that on the hot path: `billing:run` collects due cycles unattended, and a provider outage
 * would mean either skipping the collection or blocking on a call that may never return. The mandate the
 * engine charges against is therefore a row read.
 *
 * Provider-neutral by name, which is load-bearing rather than tidy: Adyen's driver stores its tokens here
 * too, and a `mollie_mandate_id` column would have forced a second table for the same concept — after
 * which "which mandate does this owner pay with" would have had two answers.
 *
 * There is no partial unique index on the default flag, though one would express the rule exactly. MySQL
 * has none, and a constraint that exists on one of the two supported engines is worse than none: it makes
 * the invariant true where it is tested and merely likely where it is not. The single default is enforced
 * in the model and pinned by tests that run on both servers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_mandates', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('provider');
            $table->string('mandate_reference');
            // How the mandate pays — a direct debit, a card, a wallet. The provider's own vocabulary,
            // carried through to MandateReference rather than mapped: the driver that wrote it is the
            // driver that reads it, and a neutral method enum would lose detail nobody else needs.
            $table->string('method');
            $table->string('status')->default('valid');
            $table->boolean('is_default')->default(false);
            // The provider customer the mandate hangs off, where a provider needs one to charge it
            // off-session. Nullable because not every provider does.
            $table->string('customer_reference')->nullable();
            $table->timestamps();

            // Per PROVIDER, not globally: two providers' id spaces are unrelated, so the same string under
            // both is a coincidence rather than a duplicate. Refusing it would leave an install running two
            // drivers unable to store a valid mandate.
            $table->unique(['provider', 'mandate_reference'], 'billing_payment_mandates_reference_unique');

            // The lookup the engine does before every off-session charge.
            $table->index(['owner_type', 'owner_id', 'provider', 'is_default'], 'billing_payment_mandates_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_mandates');
    }
};
