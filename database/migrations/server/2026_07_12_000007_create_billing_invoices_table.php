<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's own invoice model. The Stripe driver hydrates DTOs from Stripe invoices; the
 * the local engine persists them here (with a gap-free immutable number, and credit-note
 * rows linked back to the invoice they credit). Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('number')->nullable()->unique();
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('status')->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('credited_invoice_id')->nullable();
            // E-invoicing (EN 16931) fields: the buyer snapshot frozen at issue, the net + tax split,
            // and the line items — enough to render a compliant XRechnung/UBL document. The seller is
            // the platform itself (config('billing.company')), so it is not stored per invoice.
            $table->json('buyer')->nullable();
            $table->unsignedBigInteger('subtotal_minor')->nullable();
            $table->unsignedBigInteger('tax_minor')->nullable();
            $table->json('lines')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
