<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys a subscription-state row by merchant as well as by owner, so a single billable can hold many
 * concurrent subscriptions — one per creator — while a single-seller install stays byte-for-byte unchanged.
 *
 * The merchant axis is carried as a NOT-NULL sentinel string (`merchant_uid`, default `'platform'`) rather
 * than as a nullable column inside the unique index. A nullable column would make the uniqueness disappear
 * on MySQL, where two NULLs do not collide, so the single-seller one-row invariant — and the create-race
 * convergence that rides on it — would silently vanish the moment a marketplace row appeared. The sentinel
 * is an ordinary string that every engine compares identically. A real merchant's uid is `m:<type>#<id>`;
 * the `m:` prefix makes the platform sentinel structurally unreachable, so the two never collide.
 *
 * The morph columns (`merchant_type`/`merchant_id`) are nullable and carry the relation only — a
 * single-seller row leaves them null. It is the sentinel, not the morph, that the uniqueness rests on.
 *
 * A separate migration — never edit the create migration, which is published and may already have run.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('merchant_uid')->default('platform');
            $table->nullableMorphs('merchant');

            // The merchant-scoped counterpart of the owner status index, so "this merchant's active
            // subscriptions" is one indexed read rather than a scan — the shape a marketplace access-state
            // read asks for. Mirrors the (owner_type, owner_id, status) index the create migration carries.
            $table->index(['merchant_type', 'merchant_id', 'status']);

            // Swap the single-seller unique for the merchant-scoped one. With merchant_uid defaulting to
            // 'platform', every existing row reads as one merchant ('platform'), so the invariant it
            // enforced — one row per (owner, type) — is preserved exactly, now as one row per
            // (owner, type, 'platform'). The losing insert of a concurrent first delivery still hits this
            // violation and reruns, because both racers carry the same sentinel.
            $table->dropUnique('billing_subscriptions_owner_type_owner_id_type_unique');
            // Named explicitly: the conventional name for four columns overruns MySQL's 64-character
            // identifier limit, so an unnamed index migrates on SQLite and fails on MySQL.
            $table->unique(['owner_type', 'owner_id', 'type', 'merchant_uid'], 'billing_subscriptions_owner_merchant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropUnique('billing_subscriptions_owner_merchant_unique');
            $table->unique(['owner_type', 'owner_id', 'type']);

            $table->dropIndex(['merchant_type', 'merchant_id', 'status']);
            $table->dropMorphs('merchant');
            $table->dropColumn('merchant_uid');
        });
    }
};
