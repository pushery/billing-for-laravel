<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every sale put on a card reader, with the tax decided for it at the counter.
 *
 * The tax is decided when the sale goes onto the reader, and the payment is confirmed later, by the provider. The
 * row is what joins the two: the confirmation names only the payment, and the receipt has to state the place and
 * the rate that were decided before the card was presented. Reading them back from the provider's metadata instead
 * would make a document out of fields anybody with access to the provider's dashboard can edit.
 *
 * Unique on the provider and the payment's reference, so a retried sale and a redelivered confirmation each find
 * the one row. No buyer is stored: a buyer at the counter is anonymous, and the receipt names nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_in_person_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('payment_reference');
            $table->string('reader');
            $table->char('sold_at', 2);
            $table->string('description');
            $table->string('tax_archetype');
            $table->string('sold_alongside_archetype')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedInteger('tax_rate_bps');
            $table->string('tax_rate_category');
            $table->string('place_of_supply_rule');
            $table->string('tax_exemption_reason')->nullable();
            $table->string('reference')->nullable();
            $table->string('status');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'payment_reference'], 'billing_in_person_sales_payment');
            $table->index('status', 'billing_in_person_sales_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_in_person_sales');
    }
};
