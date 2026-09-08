<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a MERCHANT issue a coupon, instead of the discount namespace belonging to the platform alone.
 *
 * `code` was globally unique, which is worse than "no merchant column". It meant two merchants could not
 * both run a `SUMMER25`: the namespace was shared, so in practice it was reserved for a single issuer. On a
 * marketplace the creator discount code is the acquisition tool — it is the reason a seller advertises their
 * own subscription page anywhere — so a shared namespace does not merely limit the feature, it removes it.
 *
 * The axis is the same NOT-NULL sentinel string the subscription table keys on (`merchant_uid`, default
 * `'platform'`), for the same reason: a nullable column inside a unique index makes the uniqueness vanish on
 * MySQL, where two NULLs do not collide — so the platform's own one-code-per-name invariant would silently
 * disappear the day a merchant coupon appeared. Every existing row reads as `'platform'`, so what the old
 * index enforced is preserved exactly, now as one code per issuer.
 *
 * ## Why there are no merchant_type / merchant_id columns here
 *
 * The subscription table carries `nullableMorphs('merchant')` beside its sentinel, and this table
 * deliberately does not follow it. `morphs()` types the id column as `bigint`, so an application whose
 * merchant model is keyed by UUID or ULID cannot write it at all — every insert fails on the binding. That
 * is a known limitation of the subscription table; reproducing it in a column added today would be
 * shipping a second instance of it on purpose.
 *
 * Nothing is lost by leaving them out. The sentinel is `m:<type>#<id>` and is therefore lossless: the type
 * and the key can be read back out of it, and MerchantScope is what does that. The morph columns on the
 * subscription table exist to carry an Eloquent relation, which a coupon has no reader for.
 *
 * A separate migration — never edit the create migration, which is published and may already have run.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->string('merchant_uid')->default('platform')->after('code');

            $table->dropUnique('billing_coupons_code_unique');

            // Named explicitly: the conventional name is derived from the table and every column, and this
            // package has already been bitten by an unnamed multi-column index that migrates on SQLite and
            // overruns MySQL's 64-character identifier limit.
            $table->unique(['merchant_uid', 'code'], 'billing_coupons_merchant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->dropUnique('billing_coupons_merchant_code_unique');
            $table->unique('code');
            $table->dropColumn('merchant_uid');
        });
    }
};
