<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The key a subscriber's withdrawal declarations were recorded against, carried onto the subscription.
 *
 * The add-on purchase has carried it since the declarations were first collected; a subscription could not,
 * so a consumer that took the two declarations at its own order surface had no way to find them again once
 * the subscription existed, short of keeping a key of its own. The confirmation a buyer is owed repeats the
 * wording they were shown, and that starts with finding what they were shown.
 *
 * ## Why it is nullable, and stays nullable
 *
 * Every subscription made before this existed has none, and so does every subscription started without
 * declarations, which is the overwhelming majority and not a deficiency. Null means "no declaration traveled
 * with this subscription".
 *
 * ## Why there is no index
 *
 * Nothing looks a subscription up BY this column. It is read off a row already found by its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('declaration_reference')->nullable()->after('provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('declaration_reference');
        });
    }
};
