<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The usage units a purchase granted, and of which meter, recorded when it was made.
 *
 * ## Why the purchase has to hold them
 *
 * A refund of an add-on that granted units takes back the units that are left, in proportion to the money
 * refunded. It used to look the grant up in the add-on catalog as it stood at the time of the refund. An add-on
 * renamed or taken out of sale then took back nothing, so the buyer kept the units and the money; one that now
 * grants a different number took back a share of the wrong figure; one that moved to another meter took the units
 * from the wrong balance. The purchase knows what it granted, so it keeps it.
 *
 * ## Why both are nullable, and stay nullable
 *
 * A purchase that credited money or bought a product granted no units, and has none. A purchase recorded before
 * these columns existed has none either; its refund reads the catalog as it stands, as before.
 *
 * ## Why there is no index
 *
 * They are read off a row already found by its payment reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->string('granted_meter_key')->nullable()->after('money_credit');
            $table->bigInteger('granted_units')->nullable()->after('granted_meter_key');
        });
    }

    public function down(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->dropColumn(['granted_meter_key', 'granted_units']);
        });
    }
};
