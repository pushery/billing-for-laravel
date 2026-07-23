<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\OwnerScopedTables;

/**
 * The optional churn survey an owner leaves when canceling: one row per cancellation that carried a
 * reason. It is operational data with no legal retention — purged with the owner
 * ({@see OwnerScopedTables}), never a financial record. The reason is a stable
 * enum key (indexed, so churn can be grouped by it); the detail is free text, present only when the
 * reason is "other". Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cancellation_surveys', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('reason');
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cancellation_surveys');
    }
};
