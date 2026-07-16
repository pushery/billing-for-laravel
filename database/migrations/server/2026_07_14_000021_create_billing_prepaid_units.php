<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid usage units — an add-on that grants UNITS of a meter ("+1000 emails") rather than money.
 *
 * Three pieces, one feature:
 *
 * 1. `billing_prepaid_units` — the PERSISTENT balance, per owner per meter. It never expires and never
 *    resets: the customer paid for those units, so they roll across billing cycles. This is what makes it
 *    different from the tier's `included` allowance, which is per-cycle and lives in the (period-scoped)
 *    usage counter.
 *
 * 2. `billing_usage_counters.prepaid_used` — how much of THIS period's usage was covered by prepaid units.
 *    The flusher subtracts it before reporting, because the provider's price knows nothing about prepaid:
 *    it applies its own `included` allowance to whatever we report, so reporting `used - prepaid_used`
 *    leaves exactly the units that should actually be billed.
 *
 * 3. `billing_usage_reservations.included` — the per-cycle allowance ceiling captured when the hold was
 *    taken. settle() needs it to work out how much of the settled usage fell BEYOND `included` and therefore
 *    drew from the prepaid balance. Without it the settle would have to re-resolve tier config, and a tier
 *    change between reserve and settle would silently move the line.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_prepaid_units', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('meter_key');
            // Signed: a clawback can only ever take back what is left, but a signed column means a bug
            // surfaces as a negative balance instead of a silent MySQL strict-mode failure.
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('granted_total')->default(0); // lifetime granted, for auditing a clawback
            $table->timestamps();

            // Named explicitly: the generated name would exceed MySQL's 64-character identifier limit.
            $table->unique(['owner_type', 'owner_id', 'meter_key'], 'billing_prepaid_units_owner_meter_unique');
        });

        Schema::table('billing_usage_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('prepaid_used')->default(0)->after('reserved');
        });

        Schema::table('billing_usage_reservations', function (Blueprint $table): void {
            $table->unsignedBigInteger('included')->nullable()->after('amount');
        });

        // 4. Per-EVENT prepaid coverage. The flusher nets at the rollup, and a rollup only covers the
        //    events coalesced into it — so the coverage has to be carried by the event, not derived from
        //    the period total, or a second flush in the same cycle would subtract the same units twice.
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('prepaid_units')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dropColumn('prepaid_units');
        });

        Schema::table('billing_usage_reservations', function (Blueprint $table): void {
            $table->dropColumn('included');
        });

        Schema::table('billing_usage_counters', function (Blueprint $table): void {
            $table->dropColumn('prepaid_used');
        });

        Schema::dropIfExists('billing_prepaid_units');
    }
};
