<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger account number a merchant gets when the installation books individual creditors.
 *
 * It has to be stored rather than derived, and both unique constraints say why. A number derived from the
 * merchant id would move the moment ids do — a restore, a merge, a re-import — and a booking that pointed at
 * an account is not something a later run may reinterpret. And a number handed to two merchants merges two
 * people's obligations into one account, which reconciles perfectly and is wrong in a way nobody can see
 * from the books.
 *
 * Empty and unused in the default arrangement, where every merchant books against one collective account and
 * the platform keeps the detail in its own sub-ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_merchant_creditor_accounts', function (Blueprint $table): void {
            $table->id();
            // The merchant half is nullable so an erasure can UNLINK the row instead of deleting it. The
            // number must outlive the merchant: bookings already made point at it, and freeing it would hand
            // it to the next merchant — two people's obligations on one account, reconciling perfectly and
            // telling nobody.
            $table->nullableMorphs('merchant', 'billing_creditor_accounts_merchant_index');
            $table->string('number');
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            // Named, because the generated names run past the identifier length some engines enforce — and
            // an index that cannot be created is a constraint that silently is not there on that engine.
            $table->unique(['merchant_type', 'merchant_id'], 'billing_creditor_accounts_merchant_unique');
            $table->unique('number', 'billing_creditor_accounts_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_merchant_creditor_accounts');
    }
};
