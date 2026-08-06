<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who owns which work, and on what terms — the content-ownership register.
 *
 * ## Why this is not the existing entitlements layer
 *
 * The package already answers "what may this plan DO" (`License` / `Entitlements` / `TierResolver`). That is
 * a question about a subscription's CURRENT tier, and its answer changes the moment the tier does. This
 * table answers a different one: what did this person BUY, and does it still belong to them. A bought work
 * outlives the plan that was current when it was bought, the creator's account, and the work's own
 * publication. The names were kept apart deliberately — one word for both would guarantee that somebody
 * eventually reads a tier check as proof of ownership.
 *
 * ## Every dimension is here now, on purpose
 *
 * Ownership rows are the kind that exist for years. Adding a column later is cheap; adding one that has to
 * be RIGHT for rows already written is not — there is no true value to backfill for a grant whose terms
 * nobody recorded. So the axes that would be breaking to introduce afterwards are present from the start,
 * even where the enforcement around them comes later (`max_seats` is the clearest case: reserved, read by
 * nothing yet, and impossible to add honestly once grants exist).
 *
 * ## The references are opaque strings, and none of them is a foreign key
 *
 * `content_ref`, `version_pin_ref`, `bundle_ref` and `withdrawal_declaration_ref` all name something outside
 * this package. A foreign key would hand the referenced table a veto over ownership: deleting a work, or
 * pruning a declaration, would cascade into "this person never owned this". Deleting a work is exactly when
 * the record of who owned it matters most — for a refund, a dispute, a legal request. So the reference is
 * kept as a string and the row survives whatever it points at.
 *
 * ## The merchant axis carries a sentinel, not a nullable morph
 *
 * Same reasoning as `billing_subscriptions`: a nullable column inside a unique index makes the uniqueness
 * disappear on MySQL, where two NULLs do not collide. The platform is `'platform'`; a real merchant is
 * `m:<type>#<id>`, and the `m:` prefix makes the sentinel structurally unreachable. The morph columns carry
 * the relation only.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_access_grants', function (Blueprint $table): void {
            $table->id();

            // The principal — who owns it. A morph rather than a user id, because the package never assumes
            // what a customer is, and NO real name is needed here: ownership is a fact about an account.
            //
            // Declared column by column instead of `morphs()`, and with LENGTHS, because of the unique index
            // below. MySQL caps an index key at 3072 bytes, and utf8mb4 counts four bytes per character, so
            // four default-length strings (255 × 4 = 1020 each) overrun it before the fifth column is even
            // considered. The lengths here are what the values actually are — a class name, a short kind, an
            // external reference — and they sum to about 2.5 KB. Caught by the MySQL mirror; on SQLite and
            // PostgreSQL the same migration applies without complaint, which is exactly why that mirror
            // exists.
            $table->string('owner_type', 191);
            $table->unsignedBigInteger('owner_id');

            // Who PAID, when that is somebody else. Null on an ordinary purchase; set on a gift, where the
            // two parties are genuinely different and a refund belongs to the purchaser while the work
            // belongs to the recipient.
            $table->nullableMorphs('purchaser');

            // What is owned. An opaque reference into the host's own content, never a foreign key — see the
            // class docblock. The type is carried beside it so a consumer with several kinds of work can
            // tell them apart without parsing the reference.
            $table->string('content_type', 64);
            $table->string('content_ref', 191);

            $table->string('source');
            $table->string('status')->default('active');
            $table->timestamp('acquired_at');

            // Null means PERMANENT. A rental or a windowed license sets it. Null is not "unknown" here — a
            // grant always knows whether it ends, and a missing date is the answer "it does not".
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            // Back to the purchase that produced this grant, so money and ownership can be reconciled
            // without duplicating either. The money and tax snapshot deliberately lives ONLY on the
            // purchase: copied here it would be a second truth, and the two would part company at the first
            // correction.
            $table->string('source_reference')->nullable();

            // What the buyer was promised about later versions — stored on the grant because the creator
            // may change their policy tomorrow and that must not rewrite a sale already made.
            $table->string('update_policy')->default('latest');
            $table->string('version_pin_ref')->nullable();
            $table->timestamp('update_window_ends_at')->nullable();

            // How long the seller owes conformity for this work, and whether the buyer validly waived it.
            // A waiver is a fact about one sale, never a setting: it has to be recorded where the sale is.
            $table->timestamp('conformity_update_until')->nullable();
            $table->boolean('conformity_waiver')->default(false);

            // The withdrawal type FROZEN at purchase, and the declaration that made the right lapse. Both
            // nullable because an install with no consumer-rights profile records neither.
            //
            // The type is a SNAPSHOT of the one classification, never a second categorization: two places
            // deciding what kind of withdrawal applies is two answers, and the whole point of the taxonomy
            // is that there is one.
            $table->string('withdrawal_type')->nullable();
            $table->string('withdrawal_declaration_ref')->nullable();

            $table->string('bundle_ref')->nullable();

            // Reserved, read by nothing yet. Here because a seat count added after grants exist has no
            // honest value for the rows already written.
            $table->unsignedInteger('max_seats')->nullable();

            // Attribution, never seller status: the platform is the seller toward the buyer for every
            // content flow. A schema that made the merchant the seller would be the inverse of that.
            $table->string('merchant_uid', 191)->default('platform');
            $table->nullableMorphs('merchant');

            $table->timestamps();

            // One grant per (owner, work, merchant). The sentinel makes this hold identically on every
            // engine — with a nullable merchant column, two single-seller rows would both pass on MySQL
            // because two NULLs do not collide, and the double-ownership guard would vanish exactly when
            // the first marketplace row appeared.
            //
            // Named explicitly: the conventional name for six columns overruns MySQL's 64-character
            // identifier limit, so an unnamed index migrates on SQLite and fails on MySQL.
            $table->unique(
                ['owner_type', 'owner_id', 'content_type', 'content_ref', 'merchant_uid'],
                'billing_access_grants_owner_content_unique',
            );

            // The index `morphs()` would have created, now that the columns are declared by hand.
            $table->index(['owner_type', 'owner_id'], 'billing_access_grants_owner_index');

            // "What does this person still own" — the read a library screen makes on every page.
            $table->index(['owner_type', 'owner_id', 'status'], 'billing_access_grants_owner_status_index');

            // "Who owns this work" — the read a takedown or a creator deletion makes.
            $table->index(['content_type', 'content_ref', 'status'], 'billing_access_grants_content_index');

            // The merchant-scoped counterpart, mirroring the subscriptions table.
            $table->index(['merchant_type', 'merchant_id', 'status'], 'billing_access_grants_merchant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_access_grants');
    }
};
