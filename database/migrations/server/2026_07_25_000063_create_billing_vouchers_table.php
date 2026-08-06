<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A voucher: money paid now against a promise redeemable later, on this platform only.
 *
 * There is no column for topping one up, none for paying one out, and the holder is one party rather than
 * two. Those absences are the instrument: a balance that can be recharged, cashed out and handed on is
 * regulated money, and this stays outside that by not being able to do any of it. Guard tests hold the
 * absences so they stay properties rather than accidents.
 *
 * The instrument type is frozen at issue because it decides when the tax falls, and a supply already made
 * cannot be re-decided by a later configuration change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            // The OWNER axis, not the merchant one: a voucher belongs to whoever bought it. Nullable so an
            // erasure can unlink it rather than delete the record of money that was taken.
            $table->nullableMorphs('owner', 'billing_vouchers_owner_index');
            $table->string('currency', 3);
            $table->integer('face_value_minor');
            // What is left. It only ever goes DOWN — there is deliberately no path that raises it.
            $table->integer('remaining_minor');
            $table->string('instrument_type', 32);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('owner_erased_at')->nullable();
            $table->timestamps();

            // The volume counter asks for everything issued since a date; the expiry sweep asks for what is
            // past its date and still has value.
            $table->index('issued_at', 'billing_vouchers_issued_index');
            $table->index(['expires_at', 'expired_at'], 'billing_vouchers_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_vouchers');
    }
};
