<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * What a seller outside the Union declared to the platform, kept as it was declared.
 *
 * A platform treated as supplying the goods it facilitates (Art. 14a VAT Directive) relies on what the seller tells it,
 * and it is not liable beyond the tax it declared and paid where it can show it relied on that information and neither
 * knew nor could have known it was wrong (Art. 5c Implementing Regulation 282/2011). Each row is one declaration: where
 * the seller is established, its tax numbers, the goods it sells and where they are dispatched from, the statement the
 * seller confirmed with its hash, and the moment it arrived. A newer declaration is a new row; none is changed.
 *
 * The merchant columns are nullable and paired with `merchant_erased_at`. The declaration is evidence for the records of
 * the supplies made under it, which are kept for ten years from the end of the year of the supply, so it outlives an
 * erasure of the seller, unlinked, and retention counts its window from that erasure, the last moment a supply could
 * rest on it.
 *
 * The moments are DATETIME, as every moment the package keeps in UTC is. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_seller_declarations', function (Blueprint $table): void {
            $table->id();
            BillingSchema::nullableMorphs($table, 'merchant', 'billing_seller_declarations_merchant_index');
            $table->char('established_in', 2);
            $table->json('tax_numbers');
            $table->text('goods');
            $table->char('dispatched_from', 2)->nullable();
            $table->text('statement');
            $table->char('statement_hash', 64);
            $table->string('locale', 8);
            $table->dateTime('declared_at');
            $table->dateTime('merchant_erased_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_seller_declarations');
    }
};
